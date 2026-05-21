<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /kalender — month-grid view of every analyzed run in the last N months.
 * Each cell links to its activity detail when there's a single run on the
 * day. Color intensity reads from TRIMP. Default range is 12 months back;
 * `?months=` overrides for power users.
 */
class CalendarController extends Controller
{
    private const int DEFAULT_MONTHS = 12;

    private const int MAX_MONTHS = 24;

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $months = $this->resolveMonths($request->query('months'));
        $rangeStart = Carbon::today()->subMonthsNoOverflow($months - 1)->startOfMonth();
        $rangeEnd = Carbon::today()->endOfMonth();

        return Inertia::render('Kalender', [
            'cells' => $this->buildCells($user, $rangeStart, $rangeEnd),
            'rangeStart' => $rangeStart->toDateString(),
            'rangeEnd' => $rangeEnd->toDateString(),
            'months' => $months,
        ]);
    }

    private function resolveMonths(mixed $raw): int
    {
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_MONTHS;

        return max(1, min(self::MAX_MONTHS, $value));
    }

    /**
     * @return array<int, array{date: string, trimp: float|null, distance_km: float|null, activity_id: int|null}>
     */
    private function buildCells(User $user, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $rows = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activities.analyzed_at')
            ->whereBetween('activity_details.start_date_local', [$rangeStart, $rangeEnd])
            ->select([
                'activities.id as activity_id',
                'activity_details.start_date_local',
                'activity_details.distance',
                'activity_details.trimp_edwards',
            ])
            ->get();

        $byDay = $rows->groupBy(fn ($row): string => Carbon::parse($row->start_date_local)->toDateString())
            ->map(function ($dayRows): array {
                $first = $dayRows->first();

                return [
                    'trimp' => (float) $dayRows->sum(fn ($r) => (float) ($r->trimp_edwards ?? 0)),
                    'distance' => (float) $dayRows->sum(fn ($r) => (float) ($r->distance ?? 0)),
                    'activity_id' => $dayRows->count() === 1 && $first !== null ? (int) $first->getAttribute('activity_id') : null,
                ];
            });

        $cells = [];
        $cursor = $rangeStart->copy();
        while ($cursor->lessThanOrEqualTo($rangeEnd)) {
            $dateKey = $cursor->toDateString();
            $entry = $byDay->get($dateKey);
            $cells[] = [
                'date' => $dateKey,
                'trimp' => $entry !== null ? round($entry['trimp'], 1) : null,
                'distance_km' => $entry !== null ? round($entry['distance'] / 1000, 2) : null,
                'activity_id' => $entry['activity_id'] ?? null,
            ];
            $cursor->addDay();
        }

        return $cells;
    }
}
