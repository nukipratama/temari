<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the /ai-usage reporting payload from the analytics-schema
 * `ai_token_usages` table: totals, per-kind, per-deployment, per-user, daily,
 * the kind filter options, and $ cost. user_id lives in the analytics schema
 * while user names live in the app schema, so per-user rows are aggregated first
 * and stitched to names in PHP to avoid a fragile cross-schema join.
 *
 * Cost accuracy: rates are per-deployment, so any cross-deployment row (totals,
 * byKind, daily) is grouped by the `model` (deployment) column FIRST, costed per
 * deployment, then rolled up. This avoids attributing a whole kind/day to a
 * single "dominant" deployment when calls span multiple models.
 */
class TokenUsageReport
{
    public function __construct(private readonly LlmCostCalculator $costCalculator)
    {
    }

    /**
     * @return array{
     *     totals: array{prompt:int, completion:int, total:int, calls:int, truncated_calls:int, cost:float},
     *     byKind: list<array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_latency_ms:int|null, max_latency_ms:int|null, cost:float}>,
     *     byDeployment: list<array{deployment:string, prompt:int, completion:int, total:int, calls:int, cost:float}>,
     *     byUser: list<array{user_id:int, user_name:string|null, prompt:int, completion:int, total:int, calls:int}>,
     *     daily: list<array{day:string, prompt:int, completion:int, total:int, calls:int, cost:float}>,
     *     availableKinds: list<array{value:string, label:string}>,
     *     budget: array{todayCost:float, dailyCeiling:float|null, currency:string},
     *     priceSource: string,
     * }
     */
    public function build(Carbon $from, Carbon $to, ?string $kind): array
    {
        $baseQuery = DB::connection('analytics')->table('ai_token_usages')
            ->whereBetween('created_at', [$from, $to]);

        if ($kind !== null) {
            $baseQuery->where('kind', $kind);
        }

        $totalsAndByKind = $this->totalsAndByKind($baseQuery);
        $ceiling = config('azure_openai.daily_cost_ceiling');

        return [
            'totals' => $totalsAndByKind['totals'],
            'byKind' => $totalsAndByKind['byKind'],
            'byDeployment' => $this->byDeployment($baseQuery),
            'byUser' => $this->byUser($baseQuery),
            'daily' => $this->daily($from, $to),
            'availableKinds' => $this->availableKinds($from, $to),
            'budget' => [
                'todayCost' => $this->costCalculator->dailyCost(),
                'dailyCeiling' => $ceiling === null ? null : (float) $ceiling,
                'currency' => (string) config('azure_openai.currency', 'USD'),
            ],
            'priceSource' => $this->costCalculator->isUsingRefreshedPrices() ? 'azure-retail' : 'config-fallback',
        ];
    }

    /**
     * @param  Builder  $baseQuery
     * @return array{totals: array{prompt:int, completion:int, total:int, calls:int, truncated_calls:int, cost:float}, byKind: list<array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_latency_ms:int|null, max_latency_ms:int|null, cost:float}>}
     */
    private function totalsAndByKind(Builder $baseQuery): array
    {
        $rows = (clone $baseQuery)
            ->selectRaw(
                'kind, model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls, '.
                'SUM(CASE WHEN truncated = 1 THEN 1 ELSE 0 END) as truncated_calls, '.
                'AVG(latency_ms) as avg_latency_ms, MAX(latency_ms) as max_latency_ms'
            )
            ->groupBy('kind', 'model')
            ->get();

        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'truncated_calls' => 0, 'cost' => 0.0];

        /** @var array<string, array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_sum:float, latency_calls:int, max_latency_ms:int|null, cost:float}> $kinds */
        $kinds = [];
        foreach ($rows as $row) {
            $kindKey = (string) $row->kind;
            $prompt = (int) $row->prompt;
            $completion = (int) $row->completion;
            $cost = $this->costCalculator->costFor((string) $row->model, $prompt, $completion);

            if (! isset($kinds[$kindKey])) {
                $kinds[$kindKey] = [
                    'kind' => $kindKey,
                    'prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'truncated_calls' => 0,
                    'avg_sum' => 0.0, 'latency_calls' => 0, 'max_latency_ms' => null, 'cost' => 0.0,
                ];
            }

            $kinds[$kindKey]['prompt'] += $prompt;
            $kinds[$kindKey]['completion'] += $completion;
            $kinds[$kindKey]['total'] += (int) $row->total;
            $kinds[$kindKey]['calls'] += (int) $row->calls;
            $kinds[$kindKey]['truncated_calls'] += (int) $row->truncated_calls;
            $kinds[$kindKey]['cost'] += $cost;

            // AVG(latency_ms) over a (kind, model) subgroup is re-weighted by its
            // own call count so the kind-level average stays exact across models.
            if ($row->avg_latency_ms !== null) {
                $kinds[$kindKey]['avg_sum'] += (float) $row->avg_latency_ms * (int) $row->calls;
                $kinds[$kindKey]['latency_calls'] += (int) $row->calls;
            }
            if ($row->max_latency_ms !== null) {
                $kinds[$kindKey]['max_latency_ms'] = max(
                    $kinds[$kindKey]['max_latency_ms'] ?? 0,
                    (int) $row->max_latency_ms,
                );
            }

            $totals['prompt'] += $prompt;
            $totals['completion'] += $completion;
            $totals['total'] += (int) $row->total;
            $totals['calls'] += (int) $row->calls;
            $totals['truncated_calls'] += (int) $row->truncated_calls;
            $totals['cost'] += $cost;
        }

        $byKind = [];
        foreach ($kinds as $entry) {
            $byKind[] = [
                'kind' => $entry['kind'],
                'prompt' => $entry['prompt'],
                'completion' => $entry['completion'],
                'total' => $entry['total'],
                'calls' => $entry['calls'],
                'truncated_calls' => $entry['truncated_calls'],
                'avg_latency_ms' => $entry['latency_calls'] === 0 ? null : (int) round($entry['avg_sum'] / $entry['latency_calls']),
                'max_latency_ms' => $entry['max_latency_ms'],
                'cost' => $entry['cost'],
            ];
        }

        // Preserve the original "order by total tokens descending" contract.
        usort($byKind, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return ['totals' => $totals, 'byKind' => $byKind];
    }

    /**
     * Per-deployment (model) breakdown with $ cost, ordered by total tokens.
     *
     * @param  Builder  $baseQuery
     * @return list<array{deployment:string, prompt:int, completion:int, total:int, calls:int, cost:float}>
     */
    private function byDeployment(Builder $baseQuery): array
    {
        $rows = (clone $baseQuery)
            ->selectRaw(
                'model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls'
            )
            ->groupBy('model')
            ->orderByDesc('total')
            ->get();

        $byDeployment = [];
        foreach ($rows as $row) {
            $deployment = (string) $row->model;
            $prompt = (int) $row->prompt;
            $completion = (int) $row->completion;
            $byDeployment[] = [
                'deployment' => $deployment,
                'prompt' => $prompt,
                'completion' => $completion,
                'total' => (int) $row->total,
                'calls' => (int) $row->calls,
                'cost' => $this->costCalculator->costFor($deployment, $prompt, $completion),
            ];
        }

        return $byDeployment;
    }

    /**
     * @param  Builder  $baseQuery
     * @return list<array{user_id:int, user_name:string|null, prompt:int, completion:int, total:int, calls:int}>
     */
    private function byUser(Builder $baseQuery): array
    {
        $userRows = (clone $baseQuery)
            ->whereNotNull('user_id')
            ->selectRaw(
                'user_id, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls'
            )
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->get();

        $userNames = DB::table('users')
            ->whereIn('id', $userRows->pluck('user_id')->all())
            ->pluck('name', 'id');

        $byUser = [];
        foreach ($userRows as $row) {
            $name = $userNames[$row->user_id] ?? null;
            $byUser[] = [
                'user_id' => (int) $row->user_id,
                'user_name' => $name === null ? null : (string) $name,
                'prompt' => (int) $row->prompt,
                'completion' => (int) $row->completion,
                'total' => (int) $row->total,
                'calls' => (int) $row->calls,
            ];
        }

        return $byUser;
    }

    /**
     * Daily breakdown for the bar chart, unfiltered by kind so the chart always
     * shows the full picture regardless of the kind filter. Grouped by day +
     * model so the per-day $ cost is summed against each deployment's own rate.
     *
     * @return list<array{day:string, prompt:int, completion:int, total:int, calls:int, cost:float}>
     */
    private function daily(Carbon $from, Carbon $to): array
    {
        $dailyRows = DB::connection('analytics')->table('ai_token_usages')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                'DATE(created_at) as day, model, '.
                'SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls'
            )
            ->groupByRaw('DATE(created_at), model')
            ->orderBy('day')
            ->get();

        /** @var array<string, array{day:string, prompt:int, completion:int, total:int, calls:int, cost:float}> $days */
        $days = [];
        foreach ($dailyRows as $row) {
            $day = (string) $row->day;
            $prompt = (int) $row->prompt;
            $completion = (int) $row->completion;

            if (! isset($days[$day])) {
                $days[$day] = ['day' => $day, 'prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'cost' => 0.0];
            }

            $days[$day]['prompt'] += $prompt;
            $days[$day]['completion'] += $completion;
            $days[$day]['total'] += (int) $row->total;
            $days[$day]['calls'] += (int) $row->calls;
            $days[$day]['cost'] += $this->costCalculator->costFor((string) $row->model, $prompt, $completion);
        }

        return array_values($days);
    }

    /**
     * All distinct kinds for the filter dropdown, within the date range.
     *
     * @return list<array{value:string, label:string}>
     */
    private function availableKinds(Carbon $from, Carbon $to): array
    {
        return array_values(DB::connection('analytics')->table('ai_token_usages')
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->orderBy('kind')
            ->pluck('kind')
            ->map(fn (string $k): array => [
                'value' => $k,
                'label' => AnalysisType::tryFrom($k)?->name ?? $k,
            ])
            ->all());
    }
}
