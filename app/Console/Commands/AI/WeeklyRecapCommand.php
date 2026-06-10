<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:weekly-recap')]
#[Description('Dispatch weekly recap narration for last completed week (one LLM call per user, after the week closes)')]
class WeeklyRecapCommand extends Command
{
    public function handle(AnalysisService $service): int
    {
        // The cascade stages WeeklyRecap rows as Pending during the week (see
        // DispatchPostRunAnalysis); this narrates them once, on final data.
        $weekEnding = Carbon::today()->subWeek()->endOfWeek(Carbon::SUNDAY)->startOfDay()->toDateString();

        // Zero-run snapshots exist for CTL continuity; nothing to narrate there.
        $snapshots = WeeklySnapshot::query()
            ->where('week_ending', $weekEnding)
            ->where('runs', '>', 0)
            ->get();

        foreach ($snapshots as $snapshot) {
            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                invalidate: true,
            );
        }

        $this->info("Dispatched weekly recap for {$snapshots->count()} snapshots (week ending {$weekEnding}).");

        return self::SUCCESS;
    }
}
