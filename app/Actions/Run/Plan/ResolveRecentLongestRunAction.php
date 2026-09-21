<?php

declare(strict_types=1);

namespace App\Actions\Run\Plan;

use App\Models\ActivityDetail;
use Illuminate\Support\Carbon;

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
            ->whereBetween('start_date_local', [
                $asOf->copy()->subDays($days)->startOfDay(),
                $asOf->copy()->endOfDay(),
            ])
            ->max('distance');

        return $this->memo[$key] = $distanceM === null ? null : (float) $distanceM;
    }
}
