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

class ResolveActivityLocationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

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
        $detail = ActivityDetail::query()->find($this->activityDetailId);
        if ($detail === null || $detail->location_resolved_at !== null) {
            return;
        }

        if ($detail->start_lat === null || $detail->start_lng === null) {
            $detail->update(['location_resolved_at' => Carbon::now()]);

            return;
        }

        $resolved = $resolver->reverse($detail->start_lat, $detail->start_lng);

        // Only stamp resolved_at on a real hit. A null is a transient Nominatim
        // miss (rate limit / timeout / empty body): leaving resolved_at null keeps
        // the row eligible for the geo:backfill-locations catch-up instead of
        // marking it permanently resolved with no name.
        if ($resolved === null) {
            return;
        }

        $detail->update([
            'location_name' => $resolved->name,
            'location_country' => $resolved->country,
            'location_resolved_at' => Carbon::now(),
        ]);
    }
}
