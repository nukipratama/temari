<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\JejakFilterRequest;
use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\DetailHydrator;
use App\Services\Run\JejakQuery;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\PostRunNoteReader;
use App\Services\Run\Story\CardPresenter;
use App\Services\Run\Story\PastYouMatcher;
use App\Services\Run\Story\Temari;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    private const array RUN_INSIGHT_TYPES = [
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsight,
    ];

    /**
     * Hard cap on runs returned to the page so a wide/"all" range never ships an
     * unbounded payload. The newest runs are kept (ordered by id desc), so the
     * auto-widen guarantee of surfacing the latest run still holds; older runs
     * beyond the cap are flagged via `runsTruncated`.
     */
    private const int MAX_RUNS = 365;

    /**
     * How long one run-detail view reserves the right to re-dispatch a location
     * resolve. `ShouldBeUnique` only dedupes while the job is queued or running,
     * so this guard covers the gap after a transient Nominatim miss finishes
     * without stamping `location_resolved_at`. Matched to the job's own
     * `uniqueFor`.
     */
    private const int LOCATION_DISPATCH_GUARD_SECONDS = 600;

    /** Safety cap on weekly snapshots loaded into memory (10 years ≈ 520 weeks). */
    private const int MAX_WEEKS = 520;

    public function index(JejakFilterRequest $request, JejakQuery $jejak, PostRunNoteReader $noteReader): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $jejak->filtersFor($user, $request);
        $runsQuery = $jejak->for($user, $filters);

        // Deferred behind a closure (Inertia's `useAnalysisTrigger` poll skips
        // any prop the partial reload does not name) and memoized (four props
        // below share this one query set).
        /** @var Collection<int, Activity>|null $loadedRuns */
        $loadedRuns = null;
        $runsTruncated = false;
        $loadRuns = function () use ($runsQuery, &$loadedRuns, &$runsTruncated): Collection {
            if ($loadedRuns === null) {
                // Fetch one past the cap to detect truncation, then trim to it.
                $rows = $runsQuery->limit(self::MAX_RUNS + 1)->get();
                $runsTruncated = $rows->count() > self::MAX_RUNS;
                $loadedRuns = $rows->take(self::MAX_RUNS)->values();
            }

            return $loadedRuns;
        };

        /** @var array{notes: array<int, array{oneline: string, mood: string}>, moods: array<int, string>}|null $loadedNotes */
        $loadedNotes = null;
        $loadNotes = function () use ($noteReader, $loadRuns, &$loadedNotes): array {
            if ($loadedNotes === null) {
                $loadedNotes = $noteReader->bundleFor($loadRuns()->pluck('id')->all());
            }

            return $loadedNotes;
        };

        $currentWeekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        return Inertia::render('Activities/Feed', [
            'runs' => fn (): Collection => $loadRuns(),
            'notes' => fn (): array => $loadNotes()['notes'],
            // Persisted post-run mood per run, so the list mascot matches the
            // backend mood even before the speech (and its note) is ready.
            'moods' => fn (): array => $loadNotes()['moods'],
            'rangeFilter' => $filters->range,
            'moodFilter' => $filters->moods,
            'distanceFilter' => $filters->distanceBand,
            'sortMode' => $filters->sort,
            'weekFilter' => $filters->week?->toDateString(),
            'rangeStart' => $filters->rangeStart?->toDateString(),
            'rangeAutoWidened' => $filters->rangeAutoWidened,
            // Set as a side effect of $loadRuns, so it must resolve the runs
            // first rather than reading a flag that is still false.
            'runsTruncated' => function () use ($loadRuns, &$runsTruncated): bool {
                $loadRuns();

                return $runsTruncated;
            },
            'maxRuns' => self::MAX_RUNS,
            'weeklySnapshots' => fn (): SupportCollection => $this->weeklySnapshotPayload(
                $user,
                $filters->rangeStart,
                $filters->week,
                $currentWeekEnding,
            ),
            'journeyMatch' => fn (): ?array => $this->buildJourneyMatch($user),
        ]);
    }

    /**
     * Weekly recap rows for the range, decorated with the flags the list needs.
     *
     * @return SupportCollection<int, array<string, mixed>>
     */
    private function weeklySnapshotPayload(
        User $user,
        ?Carbon $rangeStart,
        ?Carbon $weekFilter,
        Carbon $currentWeekEnding,
    ): SupportCollection {
        $weeklySnapshots = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->when($rangeStart !== null, fn ($q) => $q->where('week_ending', '>=', $rangeStart))
            // A week deep link shows exactly that week's recap, not every recap
            // since it.
            ->when($weekFilter !== null, fn ($q) => $q->where('week_ending', '=', $weekFilter))
            ->orderByDesc('week_ending')
            ->limit(self::MAX_WEEKS)
            ->get();

        $recapAnalyses = $this->recapAnalysesFor($weeklySnapshots->all());
        $chainHeadId = $this->latestNarratedWeekId($user, $currentWeekEnding);

        return $weeklySnapshots
            ->map(fn (WeeklySnapshot $row): array => $this->decorateSnapshot(
                $row,
                $recapAnalyses[$row->id],
                $chainHeadId,
                $currentWeekEnding,
            ))
            ->values();
    }

    /**
     * The latest completed week the recap chain actually narrates (runs > 0,
     * not the in-progress week, not a zero-run rest week — either would steal
     * the head and hide "Baca ulang" on the real latest recap). Only the head
     * may regenerate, so re-narrating mid-history can't desync later links.
     *
     * Queried independently of the caller's own week range: a `week` deep link
     * (an old weekly-recap notification, revisited after later weeks have
     * closed) would otherwise narrow the candidate set to a single stale week
     * and mislabel it as the head.
     */
    private function latestNarratedWeekId(User $user, Carbon $currentWeekEnding): ?int
    {
        return WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('runs', '>', 0)
            ->where('week_ending', '!=', $currentWeekEnding->toDateString())
            ->orderByDesc('week_ending')
            ->value('id');
    }

    /**
     * One weekly-snapshot row plus the flags the list renders it with.
     *
     * @param  array<string, mixed>  $recapAnalysis
     * @return array<string, mixed>
     */
    private function decorateSnapshot(
        WeeklySnapshot $row,
        array $recapAnalysis,
        ?int $chainHeadId,
        Carbon $currentWeekEnding,
    ): array {
        return [
            ...$row->toArray(),
            'is_current_week' => $row->week_ending->equalTo($currentWeekEnding),
            'is_chain_head' => $row->id === $chainHeadId,
            'recap_analysis' => $recapAnalysis,
            'notification_retry_after_seconds' => Analysis::notificationCooldownRemaining($recapAnalysis),
        ];
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
        // Boundary dates + lifetime distance in one aggregate pass (MIN/MAX skip
        // NULL start_date_local natively); detail rows for those dates follow in
        // a second query.
        $bounds = ActivityDetail::query()
            ->forUser($user->id)
            ->selectRaw('MIN(start_date_local) as first_date, MAX(start_date_local) as latest_date, SUM(distance) as total_distance')
            ->first();

        $firstDate = $bounds?->getAttribute('first_date');
        $latestDate = $bounds?->getAttribute('latest_date');
        if ($firstDate === null || $latestDate === null || $firstDate === $latestDate) {
            return null;
        }

        $boundaryDetails = ActivityDetail::query()
            ->forUser($user->id)
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
            'total_km' => DistanceFormatter::km((float) ($bounds->getAttribute('total_distance') ?? 0)),
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
            'distance_km' => DistanceFormatter::kmOrNull($detail->distance, DistanceFormatter::EXACT),
            'pace_sec_per_km' => $paceSec,
            'avg_hr' => $detail->average_heartrate !== null ? (float) $detail->average_heartrate : null,
        ];
    }

    /**
     * @param  array<int, WeeklySnapshot>  $snapshots
     * @return array<int, array<string, mixed>>  Keyed by snapshot id.
     */
    private function recapAnalysesFor(array $snapshots): array
    {
        return Analysis::payloadsForSubjects(
            WeeklySnapshot::class,
            AnalysisType::WeeklyRecap,
            collect($snapshots)->pluck('id')->all(),
        );
    }

    public function show(Request $request, Activity $activity, PastYouMatcher $matcher, RelativeEffort $relativeEffort, CardPresenter $cards, DetailHydrator $hydrator): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

        $activity->loadMissing(['detail', 'runCard']);
        $detail = $activity->detail;
        abort_if($detail === null, 404, 'Activity not yet analyzed.');

        $hydrator->hydrate($activity->id);

        if ($detail->start_lat !== null
            && $detail->location_resolved_at === null
            && Cache::lock("geo:resolve-dispatch:{$detail->id}", self::LOCATION_DISPATCH_GUARD_SECONDS)->get()) {
            ResolveActivityLocationJob::dispatch($detail->id);
        }

        // Same partial-reload deferral as index() above: memoized because the
        // insight props plus the notification cooldown all read it.
        /** @var Collection<string, Analysis>|null $loadedAnalyses */
        $loadedAnalyses = null;
        $loadAnalyses = function () use ($activity, &$loadedAnalyses): Collection {
            /** @var Collection<string, Analysis> */
            return $loadedAnalyses ??= Analysis::query()
                ->where('subject_type', Activity::class)
                ->where('subject_id', $activity->id)
                ->whereIn('analysis_type', self::RUN_INSIGHT_TYPES)
                ->get()
                ->keyBy(fn (Analysis $row): string => $row->analysis_type->value);
        };

        $payloadFor = fn (AnalysisType $type): array => Analysis::toPayload(
            $loadAnalyses()->get($type->value),
            $type,
            Activity::class,
            $activity->id,
        );

        return Inertia::render('Runs/Show', [
            // `activity` and `detail` are already hydrated for the 404 guards
            // above, so a closure would defer nothing.
            'activity' => $activity,
            'detail' => $detail,
            'card' => fn (): ?array => $this->cardPayload($cards, $activity->runCard, $user),
            'storyLine' => fn (): ?StoryLine => StoryLine::query()
                ->where('activity_id', $activity->id)
                ->where('kind', StoryLine::KIND_POST_RUN)
                ->first(),
            // Backend-computed mood for the (rare) window before the post-run
            // StoryLine lands, so the detail mascot matches the share card
            // instead of diverging into a frontend heuristic.
            'moodFallback' => fn (): string => Temari::moodForActivityOrDefault($activity),
            // Per-activity narration is a connected + chained kind: only the chain
            // head (the user's latest run) may regenerate ("Baca ulang"); historical
            // runs are resume-only, so re-narrating mid-history can't desync the
            // later runs that quoted their old narrative.
            'isChainHead' => fn (): bool => Activity::latestIdForUser($user->id) === $activity->id,
            'speechAnalysis' => fn (): array => $payloadFor(AnalysisType::PostRunSpeech),
            'notificationRetryAfterSeconds' => fn (): ?int => Analysis::notificationCooldownRemaining(
                $payloadFor(AnalysisType::PostRunSpeech),
            ),
            'runInsight' => fn (): array => $payloadFor(AnalysisType::RunInsight),
            'pastYou' => function () use ($matcher, $hydrator, $activity, $detail): ?array {
                $match = $matcher->findMatch($activity, $detail);
                if ($match !== null) {
                    $hydrator->hydrate($match['past']->activity_id);
                }

                return $match;
            },
            'relativeEffort' => fn (): ?array => $relativeEffort->forRun($activity, $detail),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cardPayload(CardPresenter $cards, ?RunCard $card, User $user): ?array
    {
        if ($card === null) {
            return null;
        }

        return [
            ...$cards->base($card),
            'flavor_analysis' => $cards->flavorAnalysis($card),
            'edition' => $cards->edition($card, $user->id),
            'public_share_url' => route('activities.show', ['activity' => $card->activity_id]),
        ];
    }
}
