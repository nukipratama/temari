<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The athletes narration is allowed to spend on today: someone who opened the
 * app inside the active window, demo excluded. Every scheduled cadence, the
 * recap kickoffs, the self-heal sweep and the ingest cascade draw from here, so
 * the rule lives in one place rather than being restated per caller.
 *
 * @see docs/decisions/narration-spends-only-on-active-athletes.md
 */
class RecentlyActiveUsers
{
    public const int ACTIVE_WINDOW_DAYS = 7;

    /**
     * @return Collection<int, User>
     */
    public function __invoke(): Collection
    {
        return $this->query()->get();
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values($this->query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    public function includes(User $user): bool
    {
        return ! $user->is_demo && $user->last_seen_at?->gte(self::windowStart()) === true;
    }

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()
            ->notDemo()
            ->where('last_seen_at', '>=', self::windowStart());
    }

    public static function windowStart(): Carbon
    {
        return Carbon::today()->subDays(self::ACTIVE_WINDOW_DAYS);
    }
}
