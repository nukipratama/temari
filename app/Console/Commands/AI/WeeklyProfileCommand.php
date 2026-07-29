<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\Activity;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:weekly-profile')]
#[Description('Refresh the Aku-page Kata Temari voice once a week for each active user (demo excluded)')]
class WeeklyProfileCommand extends Command
{
    /**
     * The Aku-page voice carries no per-run cadence of its own, so this weekly
     * heartbeat is its only auto-refresh: each active user's "Kata Temari" line
     * re-narrates once a week on the week's updated data. Demo is excluded (it
     * never auto-bills any LLM cadence); the manual "Baca ulang" button still
     * forces an on-demand refresh between runs.
     */
    public function handle(AnalysisService $service): int
    {
        // The voice is keyed per ISO week (its narrator reads a 12-week mood
        // window), so the rolling week-key is itself the weekly regen: a new week
        // creates a fresh row, and invalidate:false never re-bills the row a
        // mid-week "Baca ulang" already filled.
        $isoWeek = Carbon::now()->isoFormat('GGGG-[W]WW');

        $activeUserIds = Activity::query()
            ->where('analyzed_at', '>=', Carbon::today()->subDays(7))
            ->whereIn('user_id', User::query()->notDemo()->select('id'))
            ->distinct()
            ->pluck('user_id');

        foreach ($activeUserIds as $userId) {
            $service->request(
                subjectOrType: AnalysisType::AkuProfileVoice->subjectType(),
                subjectId: (int) $userId,
                type: AnalysisType::AkuProfileVoice,
                discriminator: $isoWeek,
                invalidate: false,
            );
        }

        $this->info("Dispatched weekly profile refresh for {$activeUserIds->count()} active users.");

        return self::SUCCESS;
    }
}
