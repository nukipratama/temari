<?php

declare(strict_types=1);

namespace App\Console\Commands\Geo;

use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\ActivityDetail;
use App\Services\Geo\PolylineDecoder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('geo:backfill-locations {--limit=200 : Cap on rows handled per run}')]
#[Description('Backfill start coords from summary_polyline + queue resolve jobs for unresolved rows.')]
class BackfillActivityLocationsCommand extends Command
{
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

    /**
     * Extract start lat/lng from `summary_polyline` for rows synced before
     * the coord columns existed. Strava's `summary_polyline` is the canonical
     * source; no Strava round-trip needed.
     */
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
            ResolveActivityLocationJob::dispatch($detail->id);
            $count++;
        }

        return $count;
    }
}
