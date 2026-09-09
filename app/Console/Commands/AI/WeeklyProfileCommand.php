<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:weekly-profile')]
#[Description('Refresh the Profile-page Temari voice once a week for each active user (demo excluded)')]
class WeeklyProfileCommand extends Command
{
    /**
     * The Profile-page voice carries no per-run cadence of its own, so this weekly
     * heartbeat is its only auto-refresh: each active user's Temari-voice line
     * re-narrates once a week on the week's updated data. Demo is excluded (it
     * never auto-bills any LLM cadence); the manual "Reread" button still
     * forces an on-demand refresh between runs.
     */
    public function handle(AnalysisService $service, RecentlyActiveUsers $activeUsers): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        // The voice is keyed per ISO week (its narrator reads a 12-week mood
        // window), so the rolling week-key is itself the weekly regen: a new week
        // creates a fresh row, and invalidate:false never re-bills the row a
        // mid-week "Reread" already filled.
        $isoWeek = AnalysisType::currentIsoWeek();

        $users = $activeUsers();

        foreach ($users as $user) {
            $service->request(
                subjectOrType: AnalysisType::ProfileVoice->subjectType(),
                subjectId: $user->id,
                type: AnalysisType::ProfileVoice,
                discriminator: $isoWeek,
                invalidate: false,
            );
        }

        $this->info("Dispatched weekly profile refresh for {$users->count()} active users.");

        return self::SUCCESS;
    }
}
