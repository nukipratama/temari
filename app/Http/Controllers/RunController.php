<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\PostRunNoteReader;
use App\Services\Run\Story\PastYouMatcher;
use App\Services\Run\Story\Temari;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
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

    /** Safety cap on weekly snapshots loaded into memory (10 years ≈ 520 weeks). */
    private const int MAX_WEEKS = 520;

    /**
     * Selectable moods for the Jejak filter. Mirrors the `Mood` union in
     * resources/js/types/inertia.ts; anything else in `?mood=` is dropped rather
     * than 404ing, so a stale or hand-edited URL degrades to a wider view.
     */
    private const array MOODS = [
        Temari::MOOD_NYALA,
        Temari::MOOD_ENTENG,
        Temari::MOOD_OLENG,
        Temari::MOOD_LEMES,
        Temari::MOOD_MUMET,
        Temari::MOOD_ADEM,
    ];

    /**
     * Distance bands in metres as `[min inclusive, max exclusive|null]`. Cut at
     * the distances runners actually think in (5K, 10K, half marathon) rather
     * than at even numbers. `21up` is open-ended so an ultra still lands
     * somewhere.
     */
    private const array DISTANCE_BANDS = [
        '0-5' => [0, 5000],
        '5-10' => [5000, 10000],
        '10-21' => [10000, 21097],
        '21up' => [21097, null],
    ];

    /** Longest accepted `?q=` term; anything beyond is truncated, not rejected. */
    private const int MAX_SEARCH_LENGTH = 60;

    /**
     * Sort modes. `newest` is the default chronological view the week grouping
     * depends on; the other two rank runs globally, which the page renders as a
     * flat list instead (weekly recap cards only make sense in date order).
     */
    private const string SORT_NEWEST = 'newest';

    private const string SORT_LONGEST = 'longest';

    private const string SORT_FASTEST = 'fastest';

    private const array SORTS = [self::SORT_NEWEST, self::SORT_LONGEST, self::SORT_FASTEST];

    public function index(Request $request, PostRunNoteReader $noteReader): Response
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

        $moodFilter = $this->resolveMoods($request->query('mood'));
        $distanceFilter = $this->resolveDistanceBand($request->query('dist'));
        $searchFilter = $this->resolveSearch($request->query('q'));
        $sort = $this->resolveSort($request->query('sort'));
        $weekFilter = $this->resolveWeek($request->query('week'));

        // A deep link to one week (the weekly-recap notification) has to reach
        // that week regardless of how far back it is, so it overrides both the
        // requested range and the auto-widen.
        if ($weekFilter !== null) {
            $rangeStart = $weekFilter->copy()->subDays(6);
            $rangeAutoWidened = false;
        }

        $runsQuery = Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', function ($q) use ($rangeStart, $weekFilter, $distanceFilter, $searchFilter) {
                if ($rangeStart !== null) {
                    $q->where('start_date_local', '>=', $rangeStart);
                }

                // Upper bound for a single-week deep link (the lower bound comes
                // from $rangeStart above). `<` the next day so the whole Sunday
                // is included whatever time the run started.
                if ($weekFilter !== null) {
                    $q->where('start_date_local', '<', $weekFilter->copy()->addDay());
                }

                if ($distanceFilter !== null) {
                    [$min, $max] = self::DISTANCE_BANDS[$distanceFilter];
                    $q->where('distance', '>=', $min);
                    if ($max !== null) {
                        $q->where('distance', '<', $max);
                    }
                }

                if ($searchFilter !== null) {
                    // Leading wildcard, so this can't use an index. Fine at a few
                    // hundred runs per user; revisit with a FULLTEXT index if a
                    // user's history ever makes it measurable.
                    $q->where('name', 'like', '%'.addcslashes($searchFilter, '%_\\').'%');
                }
            })
            ->with(['detail' => fn ($q) => $q->select(['id', 'activity_id', 'name', 'start_date_local', 'distance', 'moving_time', 'average_heartrate', 'trimp_edwards', 'workout_type'])]);

        // Mood lives on the post-run StoryLine, which is also what the list
        // renders, so filtering there keeps the filter and the displayed mood in
        // agreement. A run whose story line hasn't been written yet carries no
        // mood and is therefore not a match for any mood.
        if ($moodFilter !== []) {
            $runsQuery->whereIn('id', StoryLine::query()
                ->select('activity_id')
                ->where('user_id', $user->id)
                ->where('kind', StoryLine::KIND_POST_RUN)
                ->whereIn('mood', $moodFilter));
        }

        $this->applySort($runsQuery, $sort);

        $runs = $runsQuery
            ->limit(self::MAX_RUNS + 1)
            ->get();

        // Fetch one past the cap to detect truncation, then trim to the cap.
        $runsTruncated = $runs->count() > self::MAX_RUNS;
        $runs = $runs->take(self::MAX_RUNS)->values();

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
        $currentWeekEnding = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        // Chain head = the latest completed week the chain actually narrates
        // (runs > 0, not the in-progress week). Matching the chain's runs>0
        // definition keeps a zero-run rest week from stealing the head and
        // hiding "Baca ulang" on the real latest recap. Only the head may
        // regenerate, so re-narrating mid-history can't desync later links.
        //
        // Queried independently of $weeklySnapshots rather than picked from it:
        // a `week` deep link (old weekly-recap notification, revisited after
        // later weeks have closed) narrows that collection to a single, often
        // stale week, which would otherwise get mislabelled as the head and
        // expose a "Baca ulang" whose actual server-side effect targets a
        // different week entirely.
        $chainHeadId = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('runs', '>', 0)
            ->where('week_ending', '!=', $currentWeekEnding->toDateString())
            ->orderByDesc('week_ending')
            ->value('id');

        $runIds = $runs->pluck('id')->all();

        return Inertia::render('Riwayat/Jejak', [
            'runs' => $runs->values(),
            'notes' => $noteReader->forActivities($runIds),
            // Persisted post-run mood per run, so the list mascot matches the
            // backend mood even before the speech (and its note) is ready.
            'moods' => $noteReader->moodsFor($runIds),
            'rangeFilter' => $effectiveRange,
            'moodFilter' => $moodFilter,
            'distanceFilter' => $distanceFilter,
            'searchFilter' => $searchFilter,
            'sortMode' => $sort,
            'weekFilter' => $weekFilter?->toDateString(),
            'rangeStart' => $rangeStart?->toDateString(),
            'rangeAutoWidened' => $rangeAutoWidened,
            'runsTruncated' => $runsTruncated,
            'maxRuns' => self::MAX_RUNS,
            'weeklySnapshots' => $weeklySnapshots->map(fn (WeeklySnapshot $row): array => [
                ...$row->toArray(),
                'is_current_week' => $row->week_ending->equalTo($currentWeekEnding),
                'is_chain_head' => $row->id === $chainHeadId,
                'recap_analysis' => $recapAnalyses[$row->id],
                'notification_retry_after_seconds' => Analysis::notificationCooldownRemaining($recapAnalyses[$row->id]),
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
            ->forUser($user->id)
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
     * Selected moods from `?mood=nyala,lemes`, keeping only known values and
     * dropping duplicates. An empty result means "no mood filter" — an unknown
     * or malformed value widens the view rather than erroring, so a stale link
     * still shows runs.
     *
     * @return array<int, string>
     */
    private function resolveMoods(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_intersect(
            array_unique(explode(',', $raw)),
            self::MOODS,
        ));
    }

    /**
     * The `?week=YYYY-MM-DD` deep-link target, normalised to that week's Sunday
     * (WeeklySnapshot.week_ending), or null when absent/malformed. Any date in
     * the week resolves to the same Sunday, so a link built from a run date
     * still lands on the right recap.
     */
    private function resolveWeek(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($raw)->endOfWeek(Carbon::SUNDAY)->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** The requested sort mode, falling back to newest for anything unknown. */
    private function resolveSort(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::SORTS, true) ? $raw : self::SORT_NEWEST;
    }

    /**
     * Ordering for the runs list. `newest` uses the activity id, which tracks
     * insertion order and needs no join. The ranked modes order by a detail
     * column, so they join it under an alias (the filter above uses a separate
     * `whereHas` subquery, so the alias avoids colliding with it).
     *
     * @param  Builder<Activity>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === self::SORT_NEWEST) {
            $query->orderByDesc('id');

            return;
        }

        $query->join('activity_details as sort_detail', 'sort_detail.activity_id', '=', 'activities.id')
            ->select('activities.*');

        if ($sort === self::SORT_LONGEST) {
            $query->orderByDesc('sort_detail.distance');

            return;
        }

        // Fastest = lowest seconds per metre. Runs missing distance or time have
        // no pace to rank, so they drop out rather than sorting as infinitely
        // fast (and the division stays safe).
        $query->where('sort_detail.distance', '>', 0)
            ->where('sort_detail.moving_time', '>', 0)
            ->orderByRaw('sort_detail.moving_time / sort_detail.distance asc');
    }

    /**
     * The selected distance band key, or null for "any distance". An unknown
     * band widens rather than errors, matching {@see self::resolveMoods()}.
     */
    private function resolveDistanceBand(mixed $raw): ?string
    {
        return is_string($raw) && array_key_exists($raw, self::DISTANCE_BANDS) ? $raw : null;
    }

    /**
     * The trimmed `?q=` term, or null when absent/blank. Capped so a pathological
     * URL can't drive an enormous LIKE.
     */
    private function resolveSearch(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $term = trim($raw);

        return $term === '' ? null : mb_substr($term, 0, self::MAX_SEARCH_LENGTH);
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

    public function show(Request $request, Activity $activity, PastYouMatcher $matcher, RelativeEffort $relativeEffort): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

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

        $speechAnalysis = $payloadFor(AnalysisType::PostRunSpeech);

        // Per-activity narration is a connected + chained kind: only the chain
        // head (the user's latest run) may regenerate ("Baca ulang"); historical
        // runs are resume-only, so re-narrating mid-history can't desync the
        // later runs that quoted their old narrative.
        $isChainHead = Activity::latestIdForUser($user->id) === $activity->id;

        return Inertia::render('Runs/Show', [
            'activity' => $activity,
            'detail' => $detail,
            'card' => $this->cardPayload($activity->runCard, $user),
            'storyLine' => $storyLine,
            // Backend-computed mood for the (rare) window before the post-run
            // StoryLine lands, so the detail mascot matches the share card
            // instead of diverging into a frontend heuristic.
            'moodFallback' => Temari::moodForActivityOrDefault($activity),
            'isChainHead' => $isChainHead,
            'speechAnalysis' => $speechAnalysis,
            'notificationRetryAfterSeconds' => Analysis::notificationCooldownRemaining($speechAnalysis),
            'insightTechnical' => $payloadFor(AnalysisType::RunInsightTechnical),
            'insightSplits' => $payloadFor(AnalysisType::RunInsightSplits),
            'insightZones' => $payloadFor(AnalysisType::RunInsightZones),
            'pastYou' => $matcher->findMatch($activity, $detail),
            'relativeEffort' => $relativeEffort->forRun($activity, $detail),
        ]);
    }

    /**
     * The card's full view now lives on this page (see docs/decisions), so it
     * carries the same flavor/edition/share fields the old `/kartu/{card}`
     * detail page used to load.
     *
     * @return array<string, mixed>|null
     */
    private function cardPayload(?RunCard $card, User $user): ?array
    {
        if ($card === null) {
            return null;
        }

        $flavorAnalysis = Analysis::query()
            ->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)
            ->first();

        // One aggregate pass for both the edition index and the rarity total,
        // instead of two separate COUNT queries.
        $editionStats = RunCard::query()
            ->forUser($user->id)
            ->where('rarity', $card->rarity)
            ->selectRaw('COUNT(*) as total, SUM(id <= ?) as edition_index', [$card->id])
            ->first();

        return [
            // Explicit whitelist (not `...$card->toArray()`) so internal columns
            // like `share_image_path` never leak into the Inertia payload —
            // mirrors CardController::cardPayload's shared shape.
            'id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'badges' => $card->badges,
            'flavor_analysis' => Analysis::toPayload($flavorAnalysis, AnalysisType::CardFlavor, RunCard::class, $card->id),
            'edition' => [
                'index' => (int) $editionStats?->getAttribute('edition_index'),
                'total' => (int) $editionStats?->getAttribute('total'),
            ],
            'public_share_url' => route('aktivitas.show', ['activity' => $card->activity_id]),
        ];
    }
}
