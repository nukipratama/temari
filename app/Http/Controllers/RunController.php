<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    private const array RUN_INSIGHT_TYPES = [
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsightTechnical,
        AnalysisType::RunInsightSplits,
        AnalysisType::RunInsightZones,
    ];

    /**
     * Range chip → days back from today. Default `8w` keeps the page snappy
     * for typical browsing while letting users pull up to a year on demand.
     * {@see self::RANGE_ALL} is the unbounded escalation (every run, any age).
     */
    private const array RANGE_DAYS = [
        '8w' => 56,
        '12w' => 84,
        '6m' => 182,
        '1y' => 365,
    ];

    /** Unbounded range: no lower bound, every analyzed run regardless of age. */
    private const string RANGE_ALL = 'all';

    /**
     * Hard cap on runs returned to the page so a wide/"all" range never ships an
     * unbounded payload. The newest runs are kept (ordered by id desc), so the
     * auto-widen guarantee of surfacing the latest run still holds; older runs
     * beyond the cap are flagged via `runsTruncated`.
     */
    private const int MAX_RUNS = 365;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $requestedRange = $this->resolveRange($request->query('range'));

        // Age (in whole days) of the newest analyzed run; null when the user has
        // no dated analyzed runs at all. Drives the auto-widen below.
        $latestRunDaysAgo = $this->latestRunDaysAgo($user);

        // If the user has runs, always show them: widen to the smallest range
        // that reaches the newest run, escalating past every preset to "all" so
        // the page never asks the user to widen the window by hand.
        $effectiveRange = $this->widenRangeToReach($requestedRange, $latestRunDaysAgo);
        $rangeAutoWidened = $effectiveRange !== $requestedRange;
        $rangeStart = $this->rangeStartFor($effectiveRange);

        $runs = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn ($q) => $rangeStart === null ? $q : $q->where('start_date_local', '>=', $rangeStart))
            ->with(['detail' => fn ($q) => $q->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards'])])
            ->orderByDesc('id')
            ->limit(self::MAX_RUNS + 1)
            ->get();

        // Fetch one past the cap to detect truncation, then trim to the cap.
        $runsTruncated = $runs->count() > self::MAX_RUNS;
        $runs = $runs->take(self::MAX_RUNS)->values();

        $weeklySnapshots = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->when($rangeStart !== null, fn ($q) => $q->where('week_ending', '>=', $rangeStart))
            ->orderByDesc('week_ending')
            ->get();

        $recapAnalyses = $this->recapAnalysesFor($weeklySnapshots->all());
        $currentWeekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        return Inertia::render('Riwayat/Jejak', [
            'runs' => $runs->values(),
            'notes' => $this->notesForActivities($runs->pluck('id')->all()),
            'rangeFilter' => $effectiveRange,
            'rangeStart' => $rangeStart?->toDateString(),
            'rangeAutoWidened' => $rangeAutoWidened,
            'runsTruncated' => $runsTruncated,
            'maxRuns' => self::MAX_RUNS,
            'weeklySnapshots' => $weeklySnapshots->map(fn (WeeklySnapshot $row): array => [
                ...$row->toArray(),
                'is_current_week' => $row->week_ending->equalTo($currentWeekEnding),
                'recap_analysis' => $recapAnalyses[$row->id] ?? Analysis::toPayload(null, AnalysisType::WeeklyRecap, WeeklySnapshot::class, $row->id),
            ])->values(),
            'journeyMatch' => $this->buildJourneyMatch($user),
        ]);
    }

    /**
     * Whole days between today and the newest dated analyzed run, or null when
     * the user has no such run. Negative ages (future-dated rows) clamp to 0.
     */
    private function latestRunDaysAgo(User $user): ?int
    {
        $latestDate = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('start_date_local')
            ->max('start_date_local');

        if ($latestDate === null) {
            return null;
        }

        return (int) max(0, Carbon::parse($latestDate)->startOfDay()->diffInDays(Carbon::today(), false));
    }

    /**
     * Smallest range whose window reaches the newest run, escalating to "all"
     * (no lower bound) when the run is older than every preset. Returns the
     * requested range untouched when the user has no runs or it already reaches.
     */
    private function widenRangeToReach(string $requestedRange, ?int $latestRunDaysAgo): string
    {
        $alreadyReaches = $latestRunDaysAgo === null
            || $requestedRange === self::RANGE_ALL
            || $latestRunDaysAgo <= self::RANGE_DAYS[$requestedRange] - 1;

        if ($alreadyReaches) {
            return $requestedRange;
        }

        foreach (self::RANGE_DAYS as $range => $days) {
            if ($latestRunDaysAgo <= $days - 1) {
                return $range;
            }
        }

        return self::RANGE_ALL;
    }

    /**
     * Lower bound for a range, or null for "all" (no lower bound, show every run).
     */
    private function rangeStartFor(string $range): ?Carbon
    {
        if ($range === self::RANGE_ALL) {
            return null;
        }

        return Carbon::today()->subDays(self::RANGE_DAYS[$range] - 1);
    }

    /**
     * First-ever activity vs latest activity — surfaces an "all-time progress"
     * delta. Hides for users with <2 activities. Pace/HR improvements use
     * signed deltas (positive = faster / lower HR = improvement).
     *
     * @return array{
     *     first: array{date: string|null, name: string|null, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: float|null},
     *     current: array{date: string|null, name: string|null, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: float|null},
     *     pace_improvement_sec: float|null,
     *     hr_improvement_bpm: float|null,
     *     total_km: float,
     * }|null
     */
    private function buildJourneyMatch(User $user): ?array
    {
        // Boundary dates + lifetime distance in one aggregate pass; detail rows
        // for those dates follow in a second query. MIN/MAX skip NULL
        // start_date_local natively (no explicit filter); SUM(distance) stays
        // unfiltered to cover every analyzed detail, including null-dated ones.
        $bounds = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->selectRaw('MIN(start_date_local) as first_date, MAX(start_date_local) as latest_date, SUM(distance) as total_distance')
            ->first();

        $firstDate = $bounds?->getAttribute('first_date');
        $latestDate = $bounds?->getAttribute('latest_date');
        if ($firstDate === null || $latestDate === null || $firstDate === $latestDate) {
            return null;
        }

        $boundaryDetails = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereIn('start_date_local', [$firstDate, $latestDate])
            ->orderBy('start_date_local')
            ->get();

        $first = $boundaryDetails->first();
        $current = $boundaryDetails->last();

        if ($first === null || $current === null || $first->id === $current->id) {
            return null;
        }

        $firstPace = $first->paceSecPerKm();
        $currentPace = $current->paceSecPerKm();
        $paceImprovement = ($firstPace !== null && $currentPace !== null)
            ? $firstPace - $currentPace
            : null;

        $firstHr = $first->average_heartrate !== null ? (float) $first->average_heartrate : null;
        $currentHr = $current->average_heartrate !== null ? (float) $current->average_heartrate : null;
        $hrImprovement = ($firstHr !== null && $currentHr !== null)
            ? $firstHr - $currentHr
            : null;

        return [
            'first' => self::summariseDetail($first, $firstPace),
            'current' => self::summariseDetail($current, $currentPace),
            'pace_improvement_sec' => $paceImprovement,
            'hr_improvement_bpm' => $hrImprovement,
            'total_km' => round((float) ($bounds->getAttribute('total_distance') ?? 0) / 1000, 1),
        ];
    }

    /**
     * @return array{date: string|null, name: string|null, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: float|null}
     */
    private static function summariseDetail(ActivityDetail $detail, ?float $paceSec): array
    {
        return [
            'date' => $detail->start_date_local?->toDateString(),
            'name' => $detail->name,
            'distance_km' => $detail->distance !== null ? round((float) $detail->distance / 1000, 2) : null,
            'pace_sec_per_km' => $paceSec,
            'avg_hr' => $detail->average_heartrate !== null ? (float) $detail->average_heartrate : null,
        ];
    }

    private function resolveRange(mixed $raw): string
    {
        $candidate = is_string($raw) ? $raw : '';

        if ($candidate === self::RANGE_ALL || array_key_exists($candidate, self::RANGE_DAYS)) {
            return $candidate;
        }

        return '8w';
    }

    /**
     * @param  array<int, int>  $activityIds
     * @return array<int, array{oneline: string, mood: string}>
     */
    private function notesForActivities(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        $moodByActivity = StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereIn('activity_id', $activityIds)
            ->pluck('mood', 'activity_id');

        $speechByActivity = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('analysis_type', AnalysisType::PostRunSpeech)
            ->where('status', AnalysisStatus::Done)
            ->whereIn('subject_id', $activityIds)
            ->pluck('content', 'subject_id');

        $notes = [];
        foreach ($activityIds as $id) {
            $speech = $speechByActivity->get($id);
            $mood = $moodByActivity->get($id);
            if ($speech === null || $speech === '' || $mood === null) {
                continue;
            }
            $notes[$id] = ['oneline' => $speech, 'mood' => $mood];
        }

        return $notes;
    }

    /**
     * @param  array<int, WeeklySnapshot>  $snapshots
     * @return array<int, array<string, mixed>>  Keyed by snapshot id.
     */
    private function recapAnalysesFor(array $snapshots): array
    {
        if ($snapshots === []) {
            return [];
        }

        $ids = collect($snapshots)->pluck('id')->all();

        $analyses = Analysis::query()
            ->where('subject_type', WeeklySnapshot::class)
            ->where('analysis_type', AnalysisType::WeeklyRecap)
            ->whereIn('subject_id', $ids)
            ->get()
            ->keyBy('subject_id');

        $payloads = [];
        foreach ($snapshots as $snapshot) {
            $payloads[$snapshot->id] = Analysis::toPayload(
                $analyses->get($snapshot->id),
                AnalysisType::WeeklyRecap,
                WeeklySnapshot::class,
                $snapshot->id,
            );
        }

        return $payloads;
    }

    public function show(Request $request, Activity $activity, PastYouMatcher $matcher): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($activity->user_id === $user->id, 404);

        $activity->loadMissing(['detail', 'runCard']);
        $detail = $activity->detail;
        abort_if($detail === null, 404, 'Activity not yet analyzed.');

        $storyLine = StoryLine::query()
            ->where('activity_id', $activity->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->first();

        $analyses = Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('subject_id', $activity->id)
            ->whereIn('analysis_type', self::RUN_INSIGHT_TYPES)
            ->get()
            ->keyBy(fn (Analysis $row): string => $row->analysis_type->value);

        if ($detail->start_lat !== null && $detail->location_resolved_at === null) {
            ResolveActivityLocationJob::dispatch($detail->id);
        }

        $payloadFor = fn (AnalysisType $type): array => Analysis::toPayload(
            $analyses->get($type->value),
            $type,
            Activity::class,
            $activity->id,
        );

        return Inertia::render('Runs/Show', [
            'activity' => $activity,
            'detail' => $detail,
            'card' => $activity->runCard,
            'storyLine' => $storyLine,
            'speechAnalysis' => $payloadFor(AnalysisType::PostRunSpeech),
            'insightTechnical' => $payloadFor(AnalysisType::RunInsightTechnical),
            'insightSplits' => $payloadFor(AnalysisType::RunInsightSplits),
            'insightZones' => $payloadFor(AnalysisType::RunInsightZones),
            'pastYou' => $matcher->findMatch($activity, $detail),
        ]);
    }
}
