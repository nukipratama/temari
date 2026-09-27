<?php

declare(strict_types=1);

namespace App\Jobs\Geo;

use App\Actions\Geo\ReverseGeocodeAction;
use App\Models\ActivityDetail;
use App\Services\Geo\NominatimRateSlotUnavailable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class ResolveActivityLocationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int RETRY_WINDOW_MINUTES = 20;

    public int $uniqueFor = self::RETRY_WINDOW_MINUTES * 60;

    public function __construct(public readonly int $activityDetailId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->activityDetailId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_WINDOW_MINUTES);
    }

    public function handle(ReverseGeocodeAction $resolver): void
    {
        $detail = ActivityDetail::query()->find($this->activityDetailId);
        if ($detail === null || $detail->location_resolved_at !== null) {
            return;
        }

        if ($detail->start_lat === null || $detail->start_lng === null) {
            $detail->update(['location_resolved_at' => Carbon::now()]);

            return;
        }

        try {
            $resolved = $resolver($detail->start_lat, $detail->start_lng);
        } catch (NominatimRateSlotUnavailable) {
            $this->release(2);

            return;
        }

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
