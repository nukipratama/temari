<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:weekly-recap')]
#[Description('Kick off the connected weekly-recap chain: narrate every completed week whose recap is not Done, oldest first')]
class WeeklyRecapCommand extends Command
{
    public function handle(AnalysisService $service): int
    {
        $lastWeekEnding = RecapPeriod::lastClosedWeekEnding();

        // Every completed week (week_ending <= the latest fully-closed week,
        // runs > 0) whose WeeklyRecap is not yet Done — Pending, Failed, or
        // never created. Ordered oldest first so the connected story narrates in
        // chronological order: the kickoff dispatches the earliest link and the
        // job chain (AnalyzeWeeklyRecapJob) walks forward to each successor once
        // its predecessor is Done. invalidate:false never re-bills a Done recap,
        // so this doubles as a daily resume safety net for stalled links.
        $snapshots = WeeklySnapshot::query()
            ->where('week_ending', '<=', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->whereIn('user_id', User::query()->notDemo()->select('id'))
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done))
            ->orderBy('week_ending')
            ->get();

        $stagger = (int) config('ai.backfill_stagger_seconds', 360);

        $snapshots->each(function (WeeklySnapshot $snapshot, int $index) use ($service, $stagger): void {
            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                delaySeconds: $index * $stagger,
                invalidate: false,
            );
        });

        $this->info("Dispatched weekly recap for {$snapshots->count()} snapshots (through week ending {$lastWeekEnding}).");

        return self::SUCCESS;
    }
}
