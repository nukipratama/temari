<?php

declare(strict_types=1);

namespace App\Jobs\Geo;

use App\Models\ActivityDetail;
use App\Services\Geo\NominatimResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Reverse-geocodes a single [[ActivityDetail]] via [[NominatimResolver]]
 * and writes the result back to the row. Always stamps
 * `location_resolved_at` — even on miss — so callers can distinguish
 * "never tried" from "tried and got nothing", preventing endless retries
 * for activities Nominatim genuinely has no data for.
 *
 * Concurrency: Nominatim's TOS allows 1 req/sec. We serialise jobs via
 * `WithoutOverlapping` keyed on the global resolver — Horizon runs them
 * one at a time even though the queue has multiple workers. The job is
 * cheap enough that a global lock is fine; the alternative (one queue
 * worker dedicated to geocoding) is more infra to maintain.
 */
class ResolveActivityLocationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    /** 10-minute uniqueness window — duplicate dispatches within this gap drop at enqueue time. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $activityDetailId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->activityDetailId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('geo:nominatim:reverse'))
                ->releaseAfter(2)
                ->expireAfter(20),
        ];
    }

    public function handle(NominatimResolver $resolver): void
    {
        /** @var ActivityDetail|null $detail */
        $detail = ActivityDetail::query()->find($this->activityDetailId);
        if ($detail === null) {
            return;
        }

        // Already resolved (or already known-missing). Re-running would
        // just waste a Nominatim quota slot.
        if ($detail->location_resolved_at !== null) {
            return;
        }

        if ($detail->start_lat === null || $detail->start_lng === null) {
            $detail->update(['location_resolved_at' => Carbon::now()]);

            return;
        }

        $resolved = $resolver->reverse($detail->start_lat, $detail->start_lng);

        $detail->update([
            'location_name' => $resolved?->name,
            'location_country' => $resolved?->country,
            'location_resolved_at' => Carbon::now(),
        ]);
    }
}
