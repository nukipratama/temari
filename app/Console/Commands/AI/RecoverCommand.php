<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisSubjectMap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:recover')]
#[Description('One-shot post-outage recovery: re-arm every dead-lettered block across users, then run the full self-heal sweep immediately (no waiting for the hourly cadence)')]
class RecoverCommand extends Command
{
    /**
     * The manual "resume everything" lever: re-arms every dead-lettered block
     * across all non-demo users, then runs ai:self-heal inline instead of
     * waiting for the next hourly sweep.
     *
     * Every dispatch is cost-safe: request(invalidate:false) never re-bills a
     * Done sibling, and the job-level pause guard reverts to Pending mid-cap.
     */
    public function handle(AnalysisService $service): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Recovery);

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
        $ownerIds = AnalysisSubjectMap::ownerIdsForRows($rows);
        $athleteIds = User::query()->notDemo()->whereIn('id', array_filter($ownerIds))->pluck('id')->flip();

        $rearmed = 0;
        foreach ($rows as $row) {
            $ownerId = $ownerIds[$row->id] ?? null;
            if ($ownerId === null || ! $athleteIds->has($ownerId)) {
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
