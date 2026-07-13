<?php

declare(strict_types=1);

namespace App\Console\Commands\Geo;

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\ActivityDetail;
use App\Services\Geo\PolylineDecoder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('geo:backfill-locations {--limit=200 : Cap on rows handled per run}')]
#[Description('Backfill start coords from summary_polyline + queue resolve jobs for unresolved rows.')]
class BackfillActivityLocationsCommand extends Command
{
    /**
     * Seconds between successive dispatches. The WithoutOverlapping lock only
     * serialises the resolve jobs, it does not space them, so a burst releases
     * jobs faster than tries=2 can survive and burns the retry budget. Staggering
     * the dispatch itself paces the queue at ~1 req/sec, which also respects
     * Nominatim's usage policy.
     */
    private const int DISPATCH_SPACING_SECONDS = 1;

    public function handle(PolylineDecoder $decoder): int
    {
        $limit = (int) $this->option('limit');

        $coordsFilled = $this->backfillCoordsFromPolyline($decoder, $limit);
        $queued = $this->queueResolveJobs($limit);

        $this->info(sprintf(
            'Backfilled %d coord pair(s) from polyline · queued %d ResolveActivityLocationJob(s) (limit %d).',
            $coordsFilled,
            $queued,
            $limit,
        ));

        return self::SUCCESS;
    }

    private function backfillCoordsFromPolyline(PolylineDecoder $decoder, int $limit): int
    {
        $query = ActivityDetail::query()
            ->whereNull('start_lat')
            ->whereNotNull('summary_polyline')
            ->orderBy('id')
            ->limit($limit);

        $count = 0;
        foreach ($query->cursor() as $detail) {
            $point = $decoder->firstPoint($detail->summary_polyline ?? '');
            if ($point === null) {
                continue;
            }
            $detail->update(['start_lat' => $point[0], 'start_lng' => $point[1]]);
            $count++;
        }

        return $count;
    }

    private function queueResolveJobs(int $limit): int
    {
        $query = ActivityDetail::query()
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->whereNull('location_resolved_at')
            ->orderBy('id')
            ->limit($limit);

        $count = 0;
        foreach ($query->cursor() as $detail) {
            ResolveActivityLocationJob::dispatch($detail->id)
                ->delay(Carbon::now()->addSeconds($count * self::DISPATCH_SPACING_SECONDS));
            $count++;
        }

        return $count;
    }
}
