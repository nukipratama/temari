<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('strava:ingest {--batch=5 : Max activities to ingest per run}')]
#[Description('Drain pending activity stubs through the ingest pipeline, oldest-first, batched to respect Strava rate limits.')]
class IngestCommand extends Command
{
    private const int DETAIL_FETCH_MAX_ATTEMPTS = 5;

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));

        $activities = Activity::query()
            ->withStubs()
            ->whereNull('analyzed_at')
            ->where('detail_fail_count', '<', self::DETAIL_FETCH_MAX_ATTEMPTS)
            ->whereHas('user.stravaConnection', fn ($query) => $query->whereNull('revoked_at'))
            ->orderBy('id')
            ->limit($batch)
            ->get();

        if ($activities->isEmpty()) {
            $this->line('No pending activities to ingest.');

            return self::SUCCESS;
        }

        foreach ($activities as $activity) {
            IngestActivityJob::dispatch($activity->id);
        }

        $this->line("Dispatched {$activities->count()} activity ingest job(s).");

        return self::SUCCESS;
    }
}
