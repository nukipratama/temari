<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:recover')]
#[Description('One-shot post-outage recovery: re-arm every dead-lettered block across users, then run the full self-heal sweep immediately (no waiting for the hourly cadence)')]
class RecoverCommand extends Command
{
    /**
     * The manual "resume everything" lever. After a day-long outage, full recovery
     * otherwise takes N per-user /ai-usage clicks + Horizon retries + per-run-page
     * clicks + up to 60 min of hourly self-heal cadence. This collapses that into
     * one action:
     *
     *   1. Re-arm every dead-lettered block across all non-demo users (reset the
     *      retry budget + re-dispatch), generalizing the per-user /ai-usage re-arm.
     *   2. Run ai:self-heal inline, which reverts stale in-flight zombies to
     *      Pending, sweeps the earliest Failed per-activity group oldest-first, and
     *      re-kicks every stalled family, instead of waiting for the next hour.
     *
     * Every dispatch is cost-safe: request(invalidate:false) never re-bills a Done
     * sibling, the job-level pause guard reverts to Pending mid-cap, and demo users
     * are excluded so this never auto-bills the shared demo account.
     */
    public function handle(AnalysisService $service): int
    {
        $rearmed = $this->rearmDeadLettered($service);
        $this->info("Re-armed {$rearmed} dead-lettered blocks.");

        $this->call('ai:self-heal');

        return self::SUCCESS;
    }

    /**
     * Reset attempts + re-dispatch every dead-lettered block owned by a non-demo
     * user. Unlike self-heal (earliest-per-family), this re-arms all of them so a
     * whole outage's backlog resumes in one pass.
     */
    private function rearmDeadLettered(AnalysisService $service): int
    {
        $rows = Analysis::query()->deadLettered()->get();
        $ownerIds = Analysis::ownerIdsForRows($rows);
        $demoUserIds = User::query()->where('is_demo', true)->pluck('id')->flip();

        $rearmed = 0;
        foreach ($rows as $row) {
            $ownerId = $ownerIds[$row->id] ?? null;
            if ($ownerId === null || $demoUserIds->has($ownerId)) {
                continue;
            }

            $row->update(['attempts' => 0]);
            $service->request(
                subjectOrType: $row->subject_type,
                subjectId: $row->subject_id,
                type: $row->analysis_type,
                discriminator: $row->discriminator,
                invalidate: false,
            );
            $rearmed++;
        }

        return $rearmed;
    }
}
