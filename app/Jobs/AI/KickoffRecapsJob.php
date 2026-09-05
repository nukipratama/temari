<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One-shot weekly + monthly recap kickoff for a single user, chained behind the
 * first-connect backfill so a new athlete's history narrates on day one instead
 * of waiting for the Monday / 1st-of-month sweep. Reads only rows the backfill
 * already wrote, so it spends no Strava budget, and the kickoff actions skip
 * every Done recap, so a re-run bills nothing.
 */
class KickoffRecapsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(KickoffWeeklyRecaps $weekly, KickoffMonthlyRecaps $monthly): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

        $weekly($this->userId);
        $monthly($this->userId);
    }
}
