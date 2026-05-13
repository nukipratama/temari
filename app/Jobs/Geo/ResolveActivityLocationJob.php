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

// Stamps location_resolved_at even on miss so we don't retry forever.
// WithoutOverlapping serialises to honor Nominatim's 1 req/sec TOS.
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
