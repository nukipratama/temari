<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\PlannedSession;
use Illuminate\Database\Eloquent\Collection;

/**
 * The athlete's planned sessions across a date range, read once per request.
 *
 * Bound `scoped()` in AppServiceProvider — {@see \App\Services\Run\Plan\CurrentWeekPlanBuilder}
 * loads a four-week window and {@see \App\Services\Run\Plan\SessionMatcher}
 * then asks which of a handful of those same days prescribed a long run. A
 * memo keyed on the exact range would miss that, so a request whose range
 * falls inside one already read is served from the wider window.
 *
 * {@see PlannedSession::booted()} drops the memo on any write; a caller that
 * deletes rows in bulk must call {@see self::forget()} itself, since a mass
 * `delete()` fires no model events.
 */
class ResolvePlannedSessionsAction
{
    /** @var array<int, list<array{from: string, to: string, rows: Collection<int, PlannedSession>}>> */
    private array $memo = [];

    /** @return Collection<int, PlannedSession> */
    public function __invoke(int $userId, string $from, string $to): Collection
    {
        foreach ($this->memo[$userId] ?? [] as $window) {
            if ($window['from'] <= $from && $window['to'] >= $to) {
                return $window['rows']->filter(
                    fn (PlannedSession $session): bool => $session->date->toDateString() >= $from
                        && $session->date->toDateString() <= $to,
                )->values();
            }
        }

        $rows = PlannedSession::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        $this->memo[$userId][] = ['from' => $from, 'to' => $to, 'rows' => $rows];

        return $rows;
    }

    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
    }
}
