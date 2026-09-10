<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\Analysis;
use App\Models\AI\ContentFilterEvent;
use App\Models\AI\TokenUsage;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds the /devtools/narration reporting payload from the analytics-schema
 * `ai_token_usages` table: totals, per-kind, per-deployment, per-origin, the
 * filter options, the stacked daily cost series, and the per-athlete rows. Spend
 * lives in the analytics schema while athlete identity and the narration rows
 * live in the app schema, so each side is aggregated first and stitched in PHP
 * to avoid a fragile cross-schema join.
 *
 * Cost accuracy: rates are per-deployment, so any cross-deployment row (totals,
 * byKind, daily) is grouped by the `model` (deployment) column FIRST, costed per
 * deployment, then rolled up. This avoids attributing a whole kind/day to a
 * single "dominant" deployment when calls span multiple models.
 */
class TokenUsageReport
{
    /** Fixed window the per-athlete money columns and the sparkline are measured over. */
    public const int ATHLETE_WINDOW_DAYS = 30;

    public function __construct(
        private readonly LlmCostCalculator $costCalculator,
        private readonly CostCeilingLedger $ceilingLedger,
        private readonly CeilingOverride $ceilingOverride,
    ) {
    }

    /**
     * @return array{
     *     totals: array{prompt:int, completion:int, total:int, calls:int, truncated_calls:int, cost:float},
     *     previousTotals: array{prompt:int, completion:int, total:int, calls:int, cost:float}|null,
     *     byKind: list<array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_latency_ms:int|null, max_latency_ms:int|null, cost:float, avg_steps:float|null, cached_pct:float|null, reasoning_pct:float|null}>,
     *     byDeployment: list<array{deployment:string, prompt:int, completion:int, total:int, calls:int, cost:float, inputPer1m:float|null, outputPer1m:float|null}>,
     *     byOrigin: list<array{origin:string, label:string, prompt:int, completion:int, total:int, calls:int, cost:float}>,
 *     availableKinds: list<array{value:string, label:string}>,
 *     availableOrigins: list<array{value:string, label:string}>,
     *     budget: array{todayCost:float, dailyCeiling:float|null, perUserCeiling:float|null, totalCeiling:float|null, athletes:int, currency:string, trippedAt:string|null, degradedFills:int},
     *     contentFilter: array{trips:int, pct:float|null},
     * }
     */
    public function build(Carbon $from, Carbon $to, ?string $kind, bool $includePrevious = true, ?string $origin = null): array
    {
        $baseQuery = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$from, $to]);

        if ($kind !== null) {
            $baseQuery->where('kind', $kind);
        }

        if ($origin !== null) {
            $baseQuery->where('origin', $origin);
        }

        $aggregate = $this->aggregate($baseQuery);
        $perUserCeiling = config('azure_openai.daily_cost_ceiling_per_user');
        $totalCeiling = config('azure_openai.daily_cost_ceiling_total');
        // `dailyCeiling` is the per-athlete slice times the athlete count: what
        // the bill would reach if every athlete spent theirs. `totalCeiling` is
        // the configured app-wide stop that binds before it.
        $athletes = User::query()->notDemo()->count();

        return [
            'totals' => $aggregate['totals'],
            'previousTotals' => $includePrevious ? $this->previousTotals($from, $to, $kind) : null,
            'byKind' => $aggregate['byKind'],
            'byDeployment' => $aggregate['byDeployment'],
            'byOrigin' => $this->byOrigin($baseQuery),
            'availableKinds' => $this->availableKinds($from, $to),
            'availableOrigins' => $this->availableOrigins($from, $to),
            'budget' => [
                'todayCost' => $this->costCalculator->dailyCost(),
                'dailyCeiling' => $perUserCeiling === null ? null : (float) $perUserCeiling * $athletes,
                'perUserCeiling' => $perUserCeiling === null ? null : (float) $perUserCeiling,
                'totalCeiling' => $totalCeiling === null ? null : (float) $totalCeiling,
                'athletes' => $athletes,
                'currency' => 'USD', // Prices are quoted in USD.
                ...$this->ceilingLedger->today(),
            ],
            'contentFilter' => $this->contentFilter($from, $to, $aggregate['totals']['calls']),
        ];
    }

    /**
     * Azure output-side content-filter trips that survived the strip-retry and
     * degraded to the rule-based filler (see AnalyzeRowJob), as a rate of the
     * calls in the same range. Unfiltered by kind/origin: a content-filter trip
     * is a pipeline-wide signal, not one this report's other filters narrow.
     *
     * @return array{trips:int, pct:float|null}
     */
    private function contentFilter(Carbon $from, Carbon $to, int $calls): array
    {
        $trips = ContentFilterEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return [
            'trips' => $trips,
            'pct' => $calls > 0 ? round(($trips / $calls) * 100, 2) : null,
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
        /** @var array<string, array{prompt:int, completion:int, total:int, calls:int, cached:int}> $models */
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
                $models[$modelKey] = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'cached' => 0];
            }
            $models[$modelKey]['prompt'] += $prompt;
            $models[$modelKey]['completion'] += $completion;
            $models[$modelKey]['total'] += (int) $row->total;
            $models[$modelKey]['calls'] += (int) $row->calls;
            $models[$modelKey]['cached'] += $cached;

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
     * @param  array<string, array{prompt:int, completion:int, total:int, calls:int, cached:int}>  $models
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
                'cost' => $this->costCalculator->costFor($deployment, $m['prompt'], $m['completion'], $m['cached']),
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

        $query = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$prevFrom, $prevTo]);

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        $rows = $query->selectRaw(
            'model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
            'SUM(total_tokens) as total, COUNT(*) as calls, SUM(cached_tokens) as cached'
        )->groupBy('model')->get();

        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'cost' => 0.0];
        foreach ($rows as $row) {
            $prompt = (int) $row->prompt;
            $completion = (int) $row->completion;
            $totals['prompt'] += $prompt;
            $totals['completion'] += $completion;
            $totals['total'] += (int) $row->total;
            $totals['calls'] += (int) $row->calls;
            $totals['cost'] += $this->costCalculator->costFor((string) $row->model, $prompt, $completion, (int) $row->cached);
        }

        return $totals;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Spend split by what started the call, the dimension `kind` cannot express:
     * one narrator answers the ingest cascade, a user's "Reread" and the hourly
     * self-heal alike. Costed per (origin, model), since deployments price
     * differently and an origin can span several.
     *
     * @param  Builder  $baseQuery
     * @return list<array{origin:string, label:string, prompt:int, completion:int, total:int, calls:int, cost:float}>
     */
    private function byOrigin(Builder $baseQuery): array
    {
        $rows = (clone $baseQuery)
            ->selectRaw(
                'origin, model, SUM(prompt_tokens) AS prompt, SUM(cached_tokens) AS cached, '
                .'SUM(completion_tokens) AS completion, SUM(total_tokens) AS total, COUNT(*) AS calls'
            )
            ->groupBy('origin', 'model')
            ->get();

        $byOrigin = [];
        foreach ($rows as $row) {
            $key = (string) $row->origin;
            $byOrigin[$key] ??= ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'cost' => 0.0];
            $byOrigin[$key]['prompt'] += (int) $row->prompt;
            $byOrigin[$key]['completion'] += (int) $row->completion;
            $byOrigin[$key]['total'] += (int) $row->total;
            $byOrigin[$key]['calls'] += (int) $row->calls;
            $byOrigin[$key]['cost'] += $this->costCalculator->costFor(
                (string) $row->model,
                (int) $row->prompt,
                (int) $row->completion,
                (int) $row->cached,
            );
        }

        $out = [];
        foreach ($byOrigin as $origin => $sums) {
            $out[] = [
                'origin' => $origin,
                'label' => AnalysisOrigin::tryFrom($origin)?->label() ?? $origin,
                ...$sums,
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $out;
    }

    /**
     * The origins present in the range, for the filter dropdown.
     *
     * @return list<array{value:string, label:string}>
     */
    private function availableOrigins(Carbon $from, Carbon $to): array
    {
        return array_values(TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->orderBy('origin')
            ->pluck('origin')
            ->map(fn (string $o): array => [
                'value' => $o,
                'label' => AnalysisOrigin::tryFrom($o)?->label() ?? $o,
            ])
            ->all());
    }

    /**
     * All distinct kinds for the filter dropdown, within the date range.
     *
     * @return list<array{value:string, label:string}>
     */
    private function availableKinds(Carbon $from, Carbon $to): array
    {
        return array_values(TokenUsage::query()->toBase()
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

    /**
     * Daily $ cost split by narrator kind, for the stacked chart. One scan
     * grouped by (day, kind, model): the model dimension is what lets each
     * segment bill against its own deployment rate before rolling up.
     *
     * `$userId` narrows the whole chart to one athlete, which is the athlete
     * filter above it. Kinds that cost nothing in range are left out of both the
     * stack and the legend.
     *
     * @return array{
     *     kinds: list<array{kind:string, label:string, cost:float}>,
     *     days: list<array{day:string, cost:float, byKind: array<string, float>}>,
     * }
     */
    public function dailyCostByKind(Carbon $from, Carbon $to, ?int $userId = null): array
    {
        $rows = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$from, $to])
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('user_id', $userId))
            ->selectRaw(
                'DATE(created_at) as day, kind, model, SUM(prompt_tokens) as prompt, '.
                'SUM(completion_tokens) as completion, SUM(cached_tokens) as cached'
            )
            ->groupByRaw('DATE(created_at), kind, model')
            ->orderBy('day')
            ->get();

        /** @var array<string, array{day:string, cost:float, byKind: array<string, float>}> $days */
        $days = [];
        /** @var array<string, float> $kindCost */
        $kindCost = [];

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $kind = (string) $row->kind;
            $cost = $this->costCalculator->costFor(
                (string) $row->model,
                (int) $row->prompt,
                (int) $row->completion,
                (int) $row->cached,
            );

            $days[$day] ??= ['day' => $day, 'cost' => 0.0, 'byKind' => []];
            $days[$day]['cost'] += $cost;
            $days[$day]['byKind'][$kind] = ($days[$day]['byKind'][$kind] ?? 0.0) + $cost;
            $kindCost[$kind] = ($kindCost[$kind] ?? 0.0) + $cost;
        }

        arsort($kindCost);

        $kinds = [];
        foreach ($kindCost as $kind => $cost) {
            // A kind that cost nothing is not a band: it would sit in the legend
            // claiming a colour no bar ever paints.
            if ($cost <= 0) {
                continue;
            }

            $key = (string) $kind;
            $type = AnalysisType::tryFrom($key);
            $kinds[] = [
                'kind' => $key,
                'label' => $type === null ? $key : $type->name,
                'cost' => $cost,
            ];
        }

        return ['kinds' => $kinds, 'days' => array_values($days)];
    }

    /**
     * One row per athlete: what they cost, whether they are capped, and what
     * their narration was served by.
     *
     * The money columns and the sparkline are fixed {@see self::ATHLETE_WINDOW_DAYS}
     * windows rather than the page's range, so "today vs their ceiling" keeps
     * meaning the same thing whatever range is selected; the quality columns
     * (served-by split, flags) follow the range, since they are a question about
     * a period rather than about a budget.
     *
     * Athletes with no spend are listed too — a silent athlete is a signal — and
     * a user_id that only exists in the metering rows (a deleted account) keeps
     * its snapshot name rather than disappearing.
     *
     * @return list<array{
     *     user_id:int, user_name:string|null, is_demo:bool, deleted:bool,
     *     today:float, last7:float, last30:float, calls:int,
     *     ceiling:float|null, ceiling_overridden:bool, capped:bool,
     *     sparkline: list<array{day:string, cost:float}>,
     *     served: array{llm:int, rule_based:int, unknown:int},
     *     flags:int, dead_lettered:int,
     * }>
     */
    public function athletes(Carbon $from, Carbon $to): array
    {
        $windowStart = Carbon::today()->subDays(self::ATHLETE_WINDOW_DAYS - 1)->startOfDay();
        $spend = $this->athleteSpend($windowStart);
        $served = $this->servedByCounts($from, $to);
        $flags = $this->flagCounts($from, $to);
        $deadLettered = $this->deadLetterCounts();
        $configCeiling = config('azure_openai.daily_cost_ceiling_per_user');

        /** @var array<int, array{name:string|null, is_demo:bool, deleted:bool}> $identities */
        $identities = [];
        foreach (User::query()->orderBy('id')->get(['id', 'name', 'is_demo']) as $user) {
            $identities[(int) $user->id] = [
                'name' => $user->name,
                'is_demo' => (bool) $user->is_demo,
                'deleted' => false,
            ];
        }
        foreach ($spend as $userId => $entry) {
            $identities[$userId] ??= [
                'name' => self::stringOrNull($entry['name']),
                'is_demo' => false,
                'deleted' => true,
            ];
        }

        $days = self::windowDays($windowStart);

        $rows = [];
        foreach ($identities as $userId => $identity) {
            $entry = $spend[$userId] ?? null;
            $override = $this->ceilingOverride->get($userId);
            $ceiling = $override ?? ($configCeiling === null ? null : (float) $configCeiling);
            $today = $entry['today'] ?? 0.0;

            $rows[] = [
                'user_id' => $userId,
                'user_name' => $identity['name'],
                'is_demo' => $identity['is_demo'],
                'deleted' => $identity['deleted'],
                'today' => $today,
                'last7' => $entry['last7'] ?? 0.0,
                'last30' => $entry['last30'] ?? 0.0,
                'calls' => $entry['calls'] ?? 0,
                'ceiling' => $ceiling,
                'ceiling_overridden' => $override !== null,
                'capped' => $ceiling !== null && $ceiling > 0 && $today >= $ceiling,
                'sparkline' => array_map(
                    fn (string $day): array => ['day' => $day, 'cost' => $entry['daily'][$day] ?? 0.0],
                    $days,
                ),
                'served' => $served[$userId] ?? ['llm' => 0, 'rule_based' => 0, 'unknown' => 0],
                'flags' => $flags[$userId] ?? 0,
                'dead_lettered' => $deadLettered[$userId] ?? 0,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['is_demo'], -$a['last30'], $a['user_id']]
            <=> [$b['is_demo'], -$b['last30'], $b['user_id']]);

        return $rows;
    }

    /**
     * Every date in the athlete window, so a sparkline has one slot per day
     * rather than only the days that happened to bill.
     *
     * @return list<string>
     */
    private static function windowDays(Carbon $windowStart): array
    {
        $days = [];
        for ($day = $windowStart->copy(); $day->lte(Carbon::today()); $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * Per-athlete spend over the athlete window, in one scan grouped by
     * (user_id, day, model).
     *
     * @return array<int, array{name:mixed, today:float, last7:float, last30:float, calls:int, daily: array<string, float>}>
     */
    private function athleteSpend(Carbon $windowStart): array
    {
        $rows = TokenUsage::query()->toBase()
            ->where('created_at', '>=', $windowStart)
            ->whereNotNull('user_id')
            ->selectRaw(
                'user_id, DATE(created_at) as day, model, MAX(user_name) as snapshot_name, '.
                'SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(cached_tokens) as cached, COUNT(*) as calls'
            )
            ->groupByRaw('user_id, DATE(created_at), model')
            ->get();

        $today = Carbon::today()->toDateString();
        $sevenDaysAgo = Carbon::today()->subDays(6)->toDateString();

        /** @var array<int, array{name:mixed, today:float, last7:float, last30:float, calls:int, daily: array<string, float>}> $spend */
        $spend = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $day = (string) $row->day;
            $cost = $this->costCalculator->costFor(
                (string) $row->model,
                (int) $row->prompt,
                (int) $row->completion,
                (int) $row->cached,
            );

            $spend[$userId] ??= [
                'name' => $row->snapshot_name,
                'today' => 0.0, 'last7' => 0.0, 'last30' => 0.0, 'calls' => 0, 'daily' => [],
            ];
            $spend[$userId]['last30'] += $cost;
            $spend[$userId]['calls'] += (int) $row->calls;
            $spend[$userId]['daily'][$day] = ($spend[$userId]['daily'][$day] ?? 0.0) + $cost;

            if ($day >= $sevenDaysAgo) {
                $spend[$userId]['last7'] += $cost;
            }
            if ($day === $today) {
                $spend[$userId]['today'] += $cost;
            }
        }

        return $spend;
    }

    /**
     * Which producer wrote each athlete's Done narration in range. `unknown` is
     * its own bucket rather than folded into rule-based: `served_by` is null for
     * every row narrated before the column shipped, and calling that rule-based
     * would invent a degradation that never happened.
     *
     * @return array<int, array{llm:int, rule_based:int, unknown:int}>
     */
    private function servedByCounts(Carbon $from, Carbon $to): array
    {
        $rows = Analysis::query()
            ->where('status', AnalysisStatus::Done)
            ->whereBetween('generated_at', [$from, $to])
            ->get(['id', 'subject_type', 'subject_id', 'served_by']);

        $owners = AnalysisSubjectMap::ownerIdsForRows($rows);

        /** @var array<int, array{llm:int, rule_based:int, unknown:int}> $counts */
        $counts = [];
        foreach ($rows as $row) {
            $userId = $owners[$row->id] ?? null;
            if ($userId === null) {
                continue;
            }

            $counts[$userId] ??= ['llm' => 0, 'rule_based' => 0, 'unknown' => 0];
            $bucket = match ($row->served_by) {
                ServedBy::Llm => 'llm',
                ServedBy::RuleBased => 'rule_based',
                null => 'unknown',
            };
            $counts[$userId][$bucket]++;
        }

        return $counts;
    }

    /**
     * Narration flags an athlete filed in range. `feedback` rows carry no
     * resolved state, so every flag in the window counts.
     *
     * @return array<int, int>
     */
    private function flagCounts(Carbon $from, Carbon $to): array
    {
        /** @var array<int, int> $counts */
        $counts = Feedback::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_id, COUNT(*) as flags')
            ->groupBy('user_id')
            ->pluck('flags', 'user_id')
            ->map(fn (mixed $flags): int => (int) $flags)
            ->all();

        return $counts;
    }

    /**
     * Blocks self-heal has given up on, per athlete. Not range-scoped: a
     * dead-lettered block stays dead-lettered until someone re-arms it, so
     * hiding one because it failed before the selected window would hide the
     * only thing on the row that needs an action.
     *
     * @return array<int, int>
     */
    private function deadLetterCounts(): array
    {
        $rows = Analysis::query()->deadLettered()->get(['id', 'subject_type', 'subject_id']);
        $owners = AnalysisSubjectMap::ownerIdsForRows($rows);

        /** @var array<int, int> $counts */
        $counts = [];
        foreach ($rows as $row) {
            $userId = $owners[$row->id] ?? null;
            if ($userId !== null) {
                $counts[$userId] = ($counts[$userId] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
