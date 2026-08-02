<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\StravaConnection;
use App\Models\User;
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
     *     previousTotals: array{prompt:int, completion:int, total:int, calls:int, cost:float}|null,
     *     byKind: list<array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_latency_ms:int|null, max_latency_ms:int|null, cost:float, avg_steps:float|null, cached_pct:float|null, reasoning_pct:float|null}>,
     *     byDeployment: list<array{deployment:string, prompt:int, completion:int, total:int, calls:int, cost:float, inputPer1m:float|null, outputPer1m:float|null}>,
     *     byUser: list<array{user_id:int, user_name:string|null, strava_athlete_id:int|null, deleted:bool, prompt:int, completion:int, total:int, calls:int}>,
     *     daily: list<array{day:string, prompt:int, completion:int, total:int, calls:int, cost:float}>,
     *     availableKinds: list<array{value:string, label:string}>,
     *     budget: array{todayCost:float, dailyCeiling:float|null, currency:string},
     * }
     */
    public function build(Carbon $from, Carbon $to, ?string $kind, bool $includePrevious = true): array
    {
        $baseQuery = DB::connection('analytics')->table('ai_token_usages')
            ->whereBetween('created_at', [$from, $to]);

        if ($kind !== null) {
            $baseQuery->where('kind', $kind);
        }

        $aggregate = $this->aggregate($baseQuery);
        $ceiling = config('azure_openai.daily_cost_ceiling');

        return [
            'totals' => $aggregate['totals'],
            'previousTotals' => $includePrevious ? $this->previousTotals($from, $to, $kind) : null,
            'byKind' => $aggregate['byKind'],
            'byDeployment' => $aggregate['byDeployment'],
            'byUser' => $this->byUser($baseQuery),
            'daily' => $this->daily($from, $to),
            'availableKinds' => $this->availableKinds($from, $to),
            'budget' => [
                'todayCost' => $this->costCalculator->dailyCost(),
                'dailyCeiling' => $ceiling === null ? null : (float) $ceiling,
                'currency' => 'USD', // Prices are quoted in USD.
            ],
        ];
    }

    /**
     * Single (kind, model) aggregate scan that feeds totals, the per-kind
     * breakdown, AND the per-deployment breakdown. byDeployment is rolled up
     * from the same rows (summed across kinds per model) rather than issuing a
     * second GROUP BY model scan over the range.
     *
     * @param  Builder  $baseQuery
     * @return array{
     *     totals: array{prompt:int, completion:int, total:int, calls:int, truncated_calls:int, cost:float},
     *     byKind: list<array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_latency_ms:int|null, max_latency_ms:int|null, cost:float, avg_steps:float|null, cached_pct:float|null, reasoning_pct:float|null}>,
     *     byDeployment: list<array{deployment:string, prompt:int, completion:int, total:int, calls:int, cost:float, inputPer1m:float|null, outputPer1m:float|null}>,
     * }
     */
    private function aggregate(Builder $baseQuery): array
    {
        $rows = (clone $baseQuery)
            ->selectRaw(
                'kind, model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls, '.
                'SUM(cached_tokens) as cached, SUM(reasoning_tokens) as reasoning, SUM(steps) as steps, '.
                'SUM(CASE WHEN truncated = 1 THEN 1 ELSE 0 END) as truncated_calls, '.
                'AVG(latency_ms) as avg_latency_ms, MAX(latency_ms) as max_latency_ms'
            )
            ->groupBy('kind', 'model')
            ->get();

        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'truncated_calls' => 0, 'cost' => 0.0];

        /** @var array<string, array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, cached:int, reasoning:int, steps:int, avg_sum:float, latency_calls:int, max_latency_ms:int|null, cost:float}> $kinds */
        $kinds = [];
        /** @var array<string, array{prompt:int, completion:int, total:int, calls:int}> $models */
        $models = [];
        foreach ($rows as $row) {
            $kindKey = (string) $row->kind;
            $modelKey = (string) $row->model;
            $prompt = (int) $row->prompt;
            $completion = (int) $row->completion;
            $cached = (int) $row->cached;
            $cost = $this->costCalculator->costFor($modelKey, $prompt, $completion, $cached);

            if (! isset($kinds[$kindKey])) {
                $kinds[$kindKey] = [
                    'kind' => $kindKey,
                    'prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'truncated_calls' => 0,
                    'cached' => 0, 'reasoning' => 0, 'steps' => 0,
                    'avg_sum' => 0.0, 'latency_calls' => 0, 'max_latency_ms' => null, 'cost' => 0.0,
                ];
            }

            $kinds[$kindKey]['prompt'] += $prompt;
            $kinds[$kindKey]['completion'] += $completion;
            $kinds[$kindKey]['total'] += (int) $row->total;
            $kinds[$kindKey]['calls'] += (int) $row->calls;
            $kinds[$kindKey]['truncated_calls'] += (int) $row->truncated_calls;
            $kinds[$kindKey]['cached'] += $cached;
            $kinds[$kindKey]['reasoning'] += (int) $row->reasoning;
            $kinds[$kindKey]['steps'] += (int) $row->steps;
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

            if (! isset($models[$modelKey])) {
                $models[$modelKey] = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0];
            }
            $models[$modelKey]['prompt'] += $prompt;
            $models[$modelKey]['completion'] += $completion;
            $models[$modelKey]['total'] += (int) $row->total;
            $models[$modelKey]['calls'] += (int) $row->calls;

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
                ...self::agentSummary($entry),
            ];
        }

        // Preserve the original "order by total tokens descending" contract.
        usort($byKind, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return ['totals' => $totals, 'byKind' => $byKind, 'byDeployment' => $this->byDeployment($models)];
    }

    /**
     * How a kind behaves as an agent: model turns per call, how much of its
     * input the provider's cache absorbed, and how much of its output went on
     * reasoning rather than the answer.
     *
     * All three are null together when no call in range recorded a step, which
     * is how rows written before these columns existed look. Reporting zero
     * would read as "never cached, never reasoned" rather than "never measured".
     *
     * @param  array{calls:int, prompt:int, completion:int, cached:int, reasoning:int, steps:int}  $entry
     * @return array{avg_steps:float|null, cached_pct:float|null, reasoning_pct:float|null}
     */
    private static function agentSummary(array $entry): array
    {
        if ($entry['steps'] === 0) {
            return ['avg_steps' => null, 'cached_pct' => null, 'reasoning_pct' => null];
        }

        return [
            'avg_steps' => $entry['calls'] === 0 ? null : round($entry['steps'] / $entry['calls'], 1),
            'cached_pct' => $entry['prompt'] === 0 ? null : round(($entry['cached'] / $entry['prompt']) * 100, 1),
            'reasoning_pct' => $entry['completion'] === 0 ? null : round(($entry['reasoning'] / $entry['completion']) * 100, 1),
        ];
    }

    /**
     * Per-deployment (model) breakdown with $ cost, ordered by total tokens,
     * built from the already-scanned (kind, model) rows.
     *
     * @param  array<string, array{prompt:int, completion:int, total:int, calls:int}>  $models
     * @return list<array{deployment:string, prompt:int, completion:int, total:int, calls:int, cost:float, inputPer1m:float|null, outputPer1m:float|null}>
     */
    private function byDeployment(array $models): array
    {
        $byDeployment = [];
        foreach ($models as $deployment => $m) {
            $rate = $this->costCalculator->priceFor($deployment);
            $byDeployment[] = [
                'deployment' => $deployment,
                'prompt' => $m['prompt'],
                'completion' => $m['completion'],
                'total' => $m['total'],
                'calls' => $m['calls'],
                'cost' => $this->costCalculator->costFor($deployment, $m['prompt'], $m['completion']),
                'inputPer1m' => $rate['input_per_1m'] ?? null,
                'outputPer1m' => $rate['output_per_1m'] ?? null,
            ];
        }

        usort($byDeployment, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $byDeployment;
    }

    /**
     * Token/cost totals for the equal-length window immediately before $from,
     * for the "vs periode sebelumnya" deltas. Grouped by model so each row is
     * costed against its own deployment rate before summing.
     *
     * @return array{prompt:int, completion:int, total:int, calls:int, cost:float}
     */
    private function previousTotals(Carbon $from, Carbon $to, ?string $kind): array
    {
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subSeconds($to->getTimestamp() - $from->getTimestamp());

        $query = DB::connection('analytics')->table('ai_token_usages')
            ->whereBetween('created_at', [$prevFrom, $prevTo]);

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        $rows = $query->selectRaw(
            'model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
            'SUM(total_tokens) as total, COUNT(*) as calls'
        )->groupBy('model')->get();

        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'cost' => 0.0];
        foreach ($rows as $row) {
            $prompt = (int) $row->prompt;
            $completion = (int) $row->completion;
            $totals['prompt'] += $prompt;
            $totals['completion'] += $completion;
            $totals['total'] += (int) $row->total;
            $totals['calls'] += (int) $row->calls;
            $totals['cost'] += $this->costCalculator->costFor((string) $row->model, $prompt, $completion);
        }

        return $totals;
    }

    /**
     * Spend per user.
     *
     * Identity is resolved live from `users` / `strava_connections` where the
     * account still exists. Where it does not, the row's own `user_name` and
     * `strava_athlete_id` are used: {@see \App\Services\User\UserEraser}
     * stamps them on the way out, so a deleted account's spend stays
     * attributable instead of collapsing to a bare id.
     *
     * @param  Builder  $baseQuery
     * @return list<array{user_id:int, user_name:string|null, strava_athlete_id:int|null, deleted:bool, prompt:int, completion:int, total:int, calls:int}>
     */
    private function byUser(Builder $baseQuery): array
    {
        $userRows = (clone $baseQuery)
            ->whereNotNull('user_id')
            ->selectRaw(
                'user_id, MAX(user_name) as snapshot_name, MAX(strava_athlete_id) as snapshot_athlete_id, '.
                'SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls'
            )
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->get();

        $ids = $userRows->pluck('user_id')->all();
        $liveNames = User::query()->whereIn('id', $ids)->pluck('name', 'id');
        $liveAthleteIds = StravaConnection::query()
            ->whereIn('user_id', $ids)
            ->pluck('strava_athlete_id', 'user_id');

        $byUser = [];
        foreach ($userRows as $row) {
            $userId = (int) $row->user_id;
            $liveName = $liveNames[$userId] ?? null;
            $athleteId = $liveAthleteIds[$userId] ?? $row->snapshot_athlete_id;

            $byUser[] = [
                'user_id' => $userId,
                'user_name' => self::stringOrNull($liveName ?? $row->snapshot_name),
                'strava_athlete_id' => $athleteId === null ? null : (int) $athleteId,
                'deleted' => $liveName === null,
                'prompt' => (int) $row->prompt,
                'completion' => (int) $row->completion,
                'total' => (int) $row->total,
                'calls' => (int) $row->calls,
            ];
        }

        return $byUser;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
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
