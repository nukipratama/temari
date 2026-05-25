<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /kalender — Google-Calendar-style single-month view. `?month=YYYY-MM`
 * selects the visible month (defaults to today's). The cell grid pads
 * leading days from the previous month + trailing days from the next so
 * the grid always renders complete weeks (Mon-Sun). Each cell carries
 * per-day distance / pace / HR / mood derived from the user's runs so
 * the frontend can render rich detail without a second query.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $month = $this->resolveMonth($request->query('month'));
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        return Inertia::render('Riwayat/Kalender', [
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $this->formatMonthLabel($monthStart),
            'prevMonth' => $monthStart->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $monthStart->copy()->addMonthNoOverflow()->format('Y-m'),
            'todayMonth' => Carbon::today()->format('Y-m'),
            'cells' => $this->buildCells($user, $gridStart, $gridEnd, $monthStart, $monthEnd),
            'lifetime' => $this->lifetimeStats($user),
            'todayQuote' => $this->todayQuote($user),
        ]);
    }

    /**
     * @return array{total_runs: int, total_km: float, first_run_at: string|null}
     */
    private function lifetimeStats(User $user): array
    {
        $totalRuns = $user->activities()->whereNotNull('analyzed_at')->count();

        $aggregates = ActivityDetail::query()
            ->whereHas(
                'activity',
                fn ($q) => $q->where('user_id', $user->id)->whereNotNull('analyzed_at'),
            )
            ->selectRaw('SUM(distance) AS total_distance, MIN(start_date_local) AS first_run_at')
            ->first();

        $totalDistanceMeters = (float) ($aggregates?->getAttribute('total_distance') ?? 0);
        $firstRunAt = $aggregates?->getAttribute('first_run_at');

        return [
            'total_runs' => $totalRuns,
            'total_km' => round($totalDistanceMeters / 1000, 1),
            'first_run_at' => \is_string($firstRunAt) ? $firstRunAt : $firstRunAt?->toIso8601String(),
        ];
    }

    private function todayQuote(User $user): ?string
    {
        return StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereHas('activity', fn ($q) => $q
                ->where('user_id', $user->id)
                ->whereNotNull('analyzed_at')
                ->whereHas('detail', fn ($q) => $q->whereDate('start_date_local', Carbon::today())))
            ->orderByDesc('id')
            ->value('speech');
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

    /**
     * @return array<int, array{date: string, day: int, is_current_month: bool, is_today: bool, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: int|null, trimp: float|null, mood: string|null, activity_id: int|null}>
     */
    private function buildCells(User $user, Carbon $gridStart, Carbon $gridEnd, Carbon $monthStart, Carbon $monthEnd): array
    {
        $details = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activities.analyzed_at')
            ->whereBetween('activity_details.start_date_local', [$gridStart, $gridEnd])
            ->select([
                'activities.id as activity_id',
                'activity_details.start_date_local',
                'activity_details.distance',
                'activity_details.moving_time',
                'activity_details.average_heartrate',
                'activity_details.trimp_edwards',
            ])
            ->get();

        $activityIds = $details->pluck('activity_id')->all();
        $moodByActivity = $this->moodsForActivities($activityIds);

        $byDay = $details->groupBy(fn ($row): string => Carbon::parse($row->start_date_local)->toDateString());

        $cells = [];
        $cursor = $gridStart->copy();
        $todayKey = Carbon::today()->toDateString();
        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $dateKey = $cursor->toDateString();
            $cells[] = $this->cellFor($cursor, $dateKey, $byDay->get($dateKey), $moodByActivity, $monthStart, $monthEnd, $todayKey);
            $cursor->addDay();
        }

        return $cells;
    }

    /**
     * @param Collection<int, ActivityDetail>|null $rows
     * @param  array<int, string>  $moodByActivity
     * @return array{date: string, day: int, is_current_month: bool, is_today: bool, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: int|null, trimp: float|null, mood: string|null, activity_id: int|null}
     */
    private function cellFor(Carbon $cursor, string $dateKey, ?Collection $rows, array $moodByActivity, Carbon $monthStart, Carbon $monthEnd, string $todayKey): array
    {
        if ($rows === null || $rows->isEmpty()) {
            return [
                'date' => $dateKey,
                'day' => $cursor->day,
                'is_current_month' => $cursor->betweenIncluded($monthStart, $monthEnd),
                'is_today' => $dateKey === $todayKey,
                'distance_km' => null,
                'pace_sec_per_km' => null,
                'avg_hr' => null,
                'trimp' => null,
                'mood' => null,
                'activity_id' => null,
            ];
        }

        $totalDistance = (float) $rows->sum(fn ($r) => (float) ($r->distance ?? 0));
        $totalMoving = (float) $rows->sum(fn ($r) => (float) ($r->moving_time ?? 0));
        $totalTrimp = (float) $rows->sum(fn ($r) => (float) ($r->trimp_edwards ?? 0));

        // Weighted average HR by moving time so longer runs dominate the day's reading.
        $hrWeighted = 0.0;
        $hrWeight = 0.0;
        foreach ($rows as $r) {
            if ($r->average_heartrate !== null && $r->moving_time !== null && $r->moving_time > 0) {
                $hrWeighted += (float) $r->average_heartrate * (float) $r->moving_time;
                $hrWeight += (float) $r->moving_time;
            }
        }

        // `$rows` is non-empty (guarded above) so `first()` is always a model.
        $primary = $rows->first();
        $primaryId = (int) $primary->getAttribute('activity_id');

        return [
            'date' => $dateKey,
            'day' => $cursor->day,
            'is_current_month' => $cursor->betweenIncluded($monthStart, $monthEnd),
            'is_today' => $dateKey === $todayKey,
            'distance_km' => round($totalDistance / 1000, 2),
            'pace_sec_per_km' => $totalDistance > 0 && $totalMoving > 0
                ? round($totalMoving / ($totalDistance / 1000), 0)
                : null,
            'avg_hr' => $hrWeight > 0 ? (int) round($hrWeighted / $hrWeight) : null,
            'trimp' => round($totalTrimp, 1),
            'mood' => $moodByActivity[$primaryId] ?? null,
            'activity_id' => $rows->count() === 1 ? $primaryId : null,
        ];
    }

    /**
     * @param  array<int, int>  $activityIds
     * @return array<int, string>
     */
    private function moodsForActivities(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        return StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereIn('activity_id', $activityIds)
            ->pluck('mood', 'activity_id')
            ->all();
    }

    private function formatMonthLabel(Carbon $month): string
    {
        $labels = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return $labels[$month->month - 1].' '.$month->year;
    }
}
