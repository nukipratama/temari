<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Run\BuildCalendarCellsAction;
use App\Http\Requests\FeedFilterRequest;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\FeedQuery;
use App\Services\Run\LifetimeStats;
use App\Services\Run\PostRunNoteReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * /history — the merged List/Calendar destination (absorbs the former
 * /activities index and /calendar pages; the run detail page itself stays at
 * /activities/{activity}, unaffected). `?view=calendar` selects the calendar
 * grid; any other value (including absent) renders the chronological list.
 * Each view's props are built independently so switching never pays for the
 * other view's queries.
 */
class HistoryController extends Controller
{
    /** Safety cap on weekly snapshots loaded into memory (10 years ≈ 520 weeks). */
    private const int MAX_WEEKS = 520;

    public function __construct(
        private readonly BuildCalendarCellsAction $calendarBuilder,
        private readonly LifetimeStats $lifetimeStats,
        private readonly PostRunNoteReader $noteReader,
    ) {
    }

    public function index(FeedFilterRequest $request, FeedQuery $feed): Response
    {
        /** @var User $user */
        $user = $request->user();
        $activeView = $request->query('view') === 'calendar' ? 'calendar' : 'list';

        return Inertia::render('History', [
            'activeView' => $activeView,
            ...$activeView === 'calendar'
                ? $this->calendarProps($request, $user)
                : $this->listProps($user, $feed, $request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listProps(User $user, FeedQuery $feed, FeedFilterRequest $request): array
    {
        $filters = $feed->filtersFor($user, $request);
        $weeks = $request->weeks();
        // P3: "load older weeks" is a real page, not a client-side reveal — the
        // server ships exactly the requested number of run-bearing week sections
        // and says whether anything sits behind them.
        ['since' => $since, 'hasOlder' => $hasOlderWeeks] = $feed->weekWindow($user, $filters, $weeks);
        $runsQuery = $feed->for($user, $filters, $since);

        // Deferred behind a closure (Inertia's `useAnalysisTrigger` poll skips
        // any prop the partial reload does not name) and memoized (three props
        // below share this one query set).
        /** @var Collection<int, Activity>|null $loadedRuns */
        $loadedRuns = null;
        $loadRuns = function () use ($runsQuery, &$loadedRuns): Collection {
            if ($loadedRuns === null) {
                $loadedRuns = $runsQuery->get();
            }

            return $loadedRuns;
        };

        /** @var array{notes: array<int, array{oneline: string, mood: string}>, moods: array<int, string>}|null $loadedNotes */
        $loadedNotes = null;
        $loadNotes = function () use ($loadRuns, &$loadedNotes): array {
            if ($loadedNotes === null) {
                $loadedNotes = $this->noteReader->bundleFor($loadRuns()->pluck('id')->all());
            }

            return $loadedNotes;
        };

        $currentWeekEnding = $this->currentWeekEnding();

        return [
            'runs' => fn (): Collection => $loadRuns(),
            'notes' => fn (): array => $loadNotes()['notes'],
            // Persisted post-run mood per run, so the list mascot matches the
            // backend mood even before the speech (and its note) is ready.
            'moods' => fn (): array => $loadNotes()['moods'],
            'rangeFilter' => $filters->range,
            'weekFilter' => $filters->week?->toDateString(),
            'rangeStart' => $filters->rangeStart?->toDateString(),
            'rangeAutoWidened' => $filters->rangeAutoWidened,
            // The header's activity count is lifetime, not page-scoped: paging
            // by week would otherwise shrink it on first paint.
            'lifetime' => $this->lifetimeStats->forUser($user),
            'weeksShown' => $weeks,
            'hasOlderWeeks' => $hasOlderWeeks,
            'weeklySnapshots' => fn (): SupportCollection => $this->weeklySnapshotPayload(
                $user,
                $since ?? $filters->rangeStart,
                $filters->week,
                $currentWeekEnding,
            ),
        ];
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
        ?Carbon $rangeEnd = null,
    ): SupportCollection {
        $weeklySnapshots = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->when($rangeStart !== null, fn ($q) => $q->where('week_ending', '>=', $rangeStart))
            ->when($rangeEnd !== null, fn ($q) => $q->where('week_ending', '<=', $rangeEnd))
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
     * the head and hide "Reread" on the real latest recap). Only the head
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

    /**
     * @return array<string, mixed>
     */
    private function calendarProps(FeedFilterRequest $request, User $user): array
    {
        $month = $this->resolveMonth($request->query('month'));
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $discriminator = $monthStart->format('Y-m');
        $recapRow = Analysis::query()
            ->forSubject(
                AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                $user->id,
                AnalysisType::MonthlyRecap,
                $discriminator,
            )
            ->first();

        $recapPayload = Analysis::toPayload(
            $recapRow,
            AnalysisType::MonthlyRecap,
            AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
            $user->id,
            $discriminator,
        );

        return [
            'month' => $discriminator,
            'monthLabel' => $this->formatMonthLabel($monthStart),
            'prevMonth' => $monthStart->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $monthStart->copy()->addMonthNoOverflow()->format('Y-m'),
            'todayMonth' => Carbon::today()->format('Y-m'),
            'cells' => ($this->calendarBuilder)($user, $gridStart, $gridEnd, $monthStart, $monthEnd),
            'lifetime' => $this->lifetimeStats->forUser($user),
            // The grid's own weeks, so each week row can disclose Temari's
            // weekly recap without leaving the calendar (prototype WeekRow).
            'weeklySnapshots' => fn (): SupportCollection => $this->weeklySnapshotPayload(
                $user,
                $gridStart,
                null,
                $this->currentWeekEnding(),
                $gridEnd,
            ),
            'monthlyRecap' => [
                ...$recapPayload,
                'is_chain_head' => $discriminator === $this->latestNarratedMonthFor($user),
                'notification_retry_after_seconds' => Analysis::notificationCooldownRemaining($recapPayload),
            ],
        ];
    }

    /**
     * The latest completed month (Y-m, strictly before the current month) the
     * user has a run in, mirroring the weekly chain-head flag: only the latest
     * narrated month may regenerate, so re-narrating mid-history can't desync
     * later links. Null when the user has no closed-month runs.
     */
    private function latestNarratedMonthFor(User $user): ?string
    {
        $currentMonthStart = Carbon::today()->startOfMonth();

        $latestDate = ActivityDetail::query()
            ->forUser($user->id)
            ->whereNotNull('start_date_local')
            ->where('start_date_local', '<', $currentMonthStart)
            ->max('start_date_local');

        return $latestDate === null ? null : Carbon::parse($latestDate)->format('Y-m');
    }

    private function currentWeekEnding(): Carbon
    {
        return Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();
    }

    private function resolveMonth(mixed $raw): Carbon
    {
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            try {
                return Carbon::parse($raw.'-01')->startOfMonth();
            } catch (Throwable) {
                // fall through to today
            }
        }

        return Carbon::today()->startOfMonth();
    }

    private function formatMonthLabel(Carbon $month): string
    {
        $labels = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        return $labels[$month->month - 1].' '.$month->year;
    }
}
