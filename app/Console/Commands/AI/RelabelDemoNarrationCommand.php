<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\ServedBy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('ai:relabel-demo-narration')]
#[Description('Correct demo-owned narration rows stamped llm that no LLM call ever paid for, restamping them rule-based')]
class RelabelDemoNarrationCommand extends Command
{
    public function handle(): int
    {
        $demoUserIds = User::query()->where('is_demo', true)->pluck('id');

        if ($demoUserIds->isEmpty()) {
            $this->info('No demo athlete; nothing to relabel.');

            return self::SUCCESS;
        }

        $candidates = $demoUserIds
            ->flatMap(fn (int $userId): Collection => AnalysisSubjectMap::whereOwnedBy(
                Analysis::query()->where('served_by', ServedBy::Llm),
                $userId,
            )->pluck('id'))
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            $this->info('No demo rows stamped llm.');

            return self::SUCCESS;
        }

        $billed = TokenUsage::query()
            ->whereIn('analysis_id', $candidates)
            ->distinct()
            ->pluck('analysis_id');

        $mislabelled = $candidates->diff($billed);

        $relabelled = $mislabelled->isEmpty()
            ? 0
            : Analysis::query()->whereIn('id', $mislabelled)->update(['served_by' => ServedBy::RuleBased]);

        $this->info("Relabelled {$relabelled} demo rows from llm to rule_based.");

        if ($billed->isNotEmpty()) {
            $this->warn(
                "Left {$billed->count()} demo rows stamped llm: a recorded token-usage row references them. Inspect before touching: ".
                $billed->implode(', ')
            );
        }

        return self::SUCCESS;
    }
}
