<?php

declare(strict_types=1);

namespace App\Console\Commands\Geo;

use App\Actions\Geo\ReverseGeocodeAction;
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
    /** Stagger queued jobs while the resolver enforces the shared request slot. */
    private const int DISPATCH_SPACING_SECONDS = 1;

    public function handle(PolylineDecoder $decoder, ReverseGeocodeAction $resolver): int
    {
        $limit = (int) $this->option('limit');

        $coordsFilled = $this->backfillCoordsFromPolyline($decoder, $limit);
        $queued = $this->queueResolveJobs($resolver, $limit);

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

    private function queueResolveJobs(ReverseGeocodeAction $resolver, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $query = ActivityDetail::query()
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->whereNull('location_resolved_at')
            ->orderBy('id');

        $count = 0;
        foreach ($query->cursor() as $detail) {
            if (
                $detail->start_lat === null
                || $detail->start_lng === null
                || $resolver->shouldSkipBackfill($detail->start_lat, $detail->start_lng)
            ) {
                continue;
            }

            ResolveActivityLocationJob::dispatch($detail->id)
                ->delay(Carbon::now()->addSeconds($count * self::DISPATCH_SPACING_SECONDS));
            $count++;
            if ($count >= $limit) {
                break;
            }
        }

        return $count;
    }
}
