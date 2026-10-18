<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\ActivityDetail;
use Illuminate\Support\Carbon;

/**
 * The longest run in the `$days` before `$asOf`'s day. A run on `$asOf` itself
 * is left out, so the session it ran never resizes itself.
 */
class ResolveRecentLongestRunAction
{
    /** @var array<string, float|null> */
    private array $memo = [];

    public function __invoke(int $userId, Carbon $asOf, int $days): ?float
    {
        $key = $userId.'|'.$asOf->toDateString().'|'.$days;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $distanceM = ActivityDetail::query()
            ->forUser($userId)
            ->where('start_date_local', '>=', $asOf->copy()->subDays($days)->startOfDay())
            ->where('start_date_local', '<', $asOf->copy()->startOfDay())
            ->max('distance');

        return $this->memo[$key] = $distanceM === null ? null : (float) $distanceM;
    }
}
