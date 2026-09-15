<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\AI\TokenUsage;
use App\Services\AI\LlmCostCalculator;
use App\Services\AI\MaintainerAlerter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:spend-digest')]
#[Description("Push today's LLM spend, per athlete and against both ceilings, to every admin on Telegram")]
class SpendDigestCommand extends Command
{
    public function handle(LlmCostCalculator $costs, MaintainerAlerter $alerter): int
    {
        $rows = $this->athleteRows($costs);

        $alerter->spendDigest(
            $rows,
            $costs->dailyCost(),
            self::ceiling('azure_openai.daily_cost_ceiling_per_user'),
            self::ceiling('azure_openai.daily_cost_ceiling_total'),
        );

        $this->info('Sent the spend digest for '.count($rows).' athletes.');

        return self::SUCCESS;
    }

    /**
     * Today's calls and tokens per athlete, priced by the same calculator the
     * ceilings read, heaviest spender first. Rows whose athlete has been erased
     * carry a null user_id and belong only in the app-wide total.
     *
     * @return list<array{userId: int, calls: int, tokens: int, cost: float}>
     */
    private function athleteRows(LlmCostCalculator $costs): array
    {
        $usage = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as calls, SUM(total_tokens) as tokens')
            ->groupBy('user_id')
            ->get();

        $rows = $usage->map(fn (object $row): array => [
            'userId' => (int) $row->user_id,
            'calls' => (int) $row->calls,
            'tokens' => (int) $row->tokens,
            'cost' => round($costs->dailyCost((int) $row->user_id), 2),
        ])->all();

        usort($rows, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return $rows;
    }

    private static function ceiling(string $configKey): ?float
    {
        $ceiling = config($configKey);

        return is_numeric($ceiling) ? (float) $ceiling : null;
    }
}
