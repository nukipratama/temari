<?php

declare(strict_types=1);

namespace App\Services\Devtools;

use App\Enums\FeedbackSubject;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\DevtoolsAction;
use App\Models\Feedback;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\AnalysisType;
use App\Services\AI\CeilingOverride;
use App\Services\AI\LlmCostCalculator;
use Illuminate\Support\Carbon;

/**
 * Everything /devtools/narration/athletes/{id} draws, for one athlete.
 *
 * The two halves live on different connections ([[narration-analytics-are-joinable]]),
 * so every join here is done in PHP: one side is queried, its ids collected, and
 * the other side queried by those ids.
 */
class AthleteNarrationReport
{
    /** Narration rows per page before the "older" cursor. */
    public const int PAGE_SIZE = 50;

    /** Audit rows shown at the bottom of the attention tab. */
    public const int AUDIT_LIMIT = 20;

    public function __construct(
        private readonly LlmCostCalculator $costs,
        private readonly CostForecast $forecast,
        private readonly CeilingOverride $override,
    ) {
    }

    /**
     * @return array{
     *     athlete: array{id:int, name:string, is_demo:bool},
     *     currency: string,
     *     today_spend: float,
     *     ceiling: array{value: float|null, source: string},
     *     sparkline: list<array{day:string, cost:float}>,
     *     forecast: array{month_to_date: float, projected: float, days_remaining: int, daily_rate: float},
     * }
     */
    public function header(User $athlete, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $windowStart = $today->copy()->subDays(29)->min($today->copy()->startOfMonth());
        $daily = $this->dailyCost($athlete->id, $windowStart, $today);

        $overridden = $this->override->get($athlete->id);
        $configured = config('azure_openai.daily_cost_ceiling_per_user');

        return [
            'athlete' => ['id' => $athlete->id, 'name' => $athlete->name, 'is_demo' => $athlete->is_demo],
            'currency' => 'USD',
            'today_spend' => $daily[$today->toDateString()] ?? 0.0,
            'ceiling' => [
                'value' => $overridden ?? (is_numeric($configured) ? (float) $configured : null),
                'source' => $overridden !== null ? 'override' : 'config',
            ],
            'sparkline' => $this->sparkline($daily, $today),
            'forecast' => $this->forecast->project(
                $this->sumBetween($daily, $today->copy()->startOfMonth(), $today),
                $this->sumBetween($daily, $today->copy()->subDays(6), $today),
                $today,
            ),
        ];
    }

    /**
     * One page of the athlete's narration rows, newest first.
     *
     * @return array{rows: list<array<string, mixed>>, next_cursor: int|null}
     */
    public function narrations(int $userId, ?AnalysisType $kind, ?AnalysisStatus $status, ?int $before): array
    {
        $query = AnalysisSubjectMap::whereOwnedBy(Analysis::query()->knownType(), $userId)
            ->when($kind !== null, fn ($q) => $q->where('analysis_type', $kind))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($before !== null, fn ($q) => $q->where('ai_analyses.id', '<', $before));

        $page = $query->orderByDesc('ai_analyses.id')->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $page->count() > self::PAGE_SIZE;
        $rows = $page->take(self::PAGE_SIZE);

        /** @var list<int> $ids */
        $ids = $rows->pluck('id')->values()->all();
        $usage = $this->usageByAnalysis($ids);
        $versions = $this->versionsByAnalysis($ids);
        $flags = $this->flagsByAnalysis($ids);

        return [
            'rows' => array_values($rows->map(fn (Analysis $row): array => [
                'id' => $row->id,
                'kind' => $row->analysis_type->value,
                'discriminator' => $row->discriminator,
                'status' => $row->status->value,
                'served_by' => $row->served_by?->value,
                'origin' => $usage[$row->id]['origin'] ?? null,
                'cost' => $usage[$row->id]['cost'] ?? 0.0,
                'last_cost' => $usage[$row->id]['last_cost'] ?? 0.0,
                'prompt_tokens' => $usage[$row->id]['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage[$row->id]['completion_tokens'] ?? 0,
                'latency_ms' => $usage[$row->id]['latency_ms'] ?? null,
                'steps' => $usage[$row->id]['steps'] ?? 0,
                'tool_calls' => $usage[$row->id]['tool_calls'] ?? [],
                'content' => $row->content,
                'error' => $row->error,
                'generated_at' => $row->generated_at?->toIso8601String(),
                'flag' => $flags[$row->id] ?? null,
                'version_count' => $versions[$row->id]['count'] ?? 0,
                'previous_content' => $versions[$row->id]['previous_content'] ?? null,
            ])->all()),
            'next_cursor' => $hasMore ? (int) $rows->last()?->id : null,
        ];
    }

    /**
     * Spend and call count per narrator kind over today / 7d / 30d.
     *
     * @return list<array{kind:string, today:array{cost:float, calls:int}, week:array{cost:float, calls:int}, month:array{cost:float, calls:int}}>
     */
    public function costByKind(int $userId, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $from = $today->copy()->subDays(29);

        $rows = TokenUsage::query()->toBase()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->selectRaw('kind, model, DATE(created_at) as day, COUNT(*) as calls, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, SUM(cached_tokens) as cached')
            ->groupBy('kind', 'model', 'day')
            ->get();

        $todayString = $today->toDateString();
        $weekStart = $today->copy()->subDays(6)->toDateString();

        /** @var array<string, array<string, array{cost:float, calls:int}>> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $kind = (string) $row->kind;
            $day = (string) $row->day;
            $cost = $this->costs->costFor((string) $row->model, (int) $row->prompt, (int) $row->completion, (int) $row->cached);

            foreach (['month', $day >= $weekStart ? 'week' : null, $day === $todayString ? 'today' : null] as $bucket) {
                if ($bucket === null) {
                    continue;
                }
                $buckets[$kind][$bucket]['cost'] = ($buckets[$kind][$bucket]['cost'] ?? 0.0) + $cost;
                $buckets[$kind][$bucket]['calls'] = ($buckets[$kind][$bucket]['calls'] ?? 0) + (int) $row->calls;
            }
        }

        $empty = ['cost' => 0.0, 'calls' => 0];
        $out = [];
        foreach ($buckets as $kind => $bucket) {
            $out[] = [
                'kind' => $kind,
                'today' => $bucket['today'] ?? $empty,
                'week' => $bucket['week'] ?? $empty,
                'month' => $bucket['month'] ?? $empty,
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['month']['cost'] <=> $a['month']['cost']);

        return $out;
    }

    /**
     * The three buckets an operator acts on: failed but still auto-retrying,
     * dead-lettered, and in-flight rows whose job was lost.
     *
     * @return array{failed: list<array<string, mixed>>, dead_lettered: list<array<string, mixed>>, stuck: list<array<string, mixed>>}
     */
    public function attention(int $userId): array
    {
        $stale = Carbon::now()->subHours(Analysis::STALE_IN_FLIGHT_HOURS);

        $failed = AnalysisSubjectMap::whereOwnedBy(
            Analysis::query()->knownType()->where('status', AnalysisStatus::Failed),
            $userId,
        )->orderByDesc('updated_at')->get();

        $stuck = AnalysisSubjectMap::whereOwnedBy(Analysis::query()->staleInFlight($stale), $userId)
            ->orderBy('created_at')
            ->get();

        return [
            'failed' => $this->blocks($failed->filter(
                fn (Analysis $row): bool => $row->attempts < Analysis::MAX_SELF_HEAL_ATTEMPTS,
            )),
            'dead_lettered' => $this->blocks($failed->filter(
                fn (Analysis $row): bool => $row->attempts >= Analysis::MAX_SELF_HEAL_ATTEMPTS,
            )),
            'stuck' => $this->blocks($stuck),
        ];
    }

    /**
     * The last {@see self::AUDIT_LIMIT} operator actions taken on this athlete.
     *
     * @return list<array{actor:string, action:string, payload:array<string, mixed>|null, at:string}>
     */
    public function audit(int $userId): array
    {
        return array_values(DevtoolsAction::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::AUDIT_LIMIT)
            ->get()
            ->map(fn (DevtoolsAction $row): array => [
                'actor' => $row->actor,
                'action' => $row->action,
                'payload' => $row->payload,
                'at' => $row->created_at->toIso8601String(),
            ])
            ->all());
    }

    /** @return array{value: float, expires_at: string}|null */
    public function activeOverride(int $userId): ?array
    {
        $value = $this->override->get($userId);

        return $value === null
            ? null
            : ['value' => $value, 'expires_at' => Carbon::tomorrow()->toIso8601String()];
    }

    /**
     * @param  iterable<int, Analysis>  $rows
     * @return list<array<string, mixed>>
     */
    private function blocks(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => $row->id,
                'kind' => $row->analysis_type->value,
                'status' => $row->status->value,
                'attempts' => $row->attempts,
                'error' => $row->error,
                'at' => $row->updated_at->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{cost:float, last_cost:float, prompt_tokens:int, completion_tokens:int, latency_ms:int|null, steps:int, tool_calls:list<array<string, mixed>>, origin:string}>
     */
    private function usageByAnalysis(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = TokenUsage::query()->whereIn('analysis_id', $ids)->orderBy('id')->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->analysis_id;
            $out[$id] ??= [
                'cost' => 0.0,
                'last_cost' => 0.0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'latency_ms' => 0,
                'steps' => 0,
                'tool_calls' => [],
                'origin' => $row->origin->value,
            ];
            $cost = $this->costs->costFor((string) $row->model, $row->prompt_tokens, $row->completion_tokens, $row->cached_tokens);
            $out[$id]['cost'] += $cost;
            $out[$id]['last_cost'] = $cost;
            $out[$id]['prompt_tokens'] += $row->prompt_tokens;
            $out[$id]['completion_tokens'] += $row->completion_tokens;
            $out[$id]['latency_ms'] += $row->latency_ms ?? 0;
            $out[$id]['steps'] += $row->steps;
            $out[$id]['tool_calls'] = array_merge($out[$id]['tool_calls'], $row->tool_calls ?? []);
            $out[$id]['origin'] = $row->origin->value;
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{count:int, previous_content:string}>
     */
    private function versionsByAnalysis(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (AnalysisVersion::query()->whereIn('analysis_id', $ids)->orderBy('id')->get() as $version) {
            $id = $version->analysis_id;
            $out[$id] = [
                'count' => ($out[$id]['count'] ?? 0) + 1,
                'previous_content' => $version->content,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{reason:string|null, note:string|null, at:string|null}>
     */
    private function flagsByAnalysis(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = Feedback::query()
            ->where('subject_type', FeedbackSubject::Narration)
            ->whereIn('subject_id', $ids)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->subject_id] = [
                'reason' => $row->reason?->value,
                'note' => $row->note,
                'at' => $row->created_at?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Cost per calendar day for one athlete over a window, keyed by `Y-m-d`.
     * One query feeds today, month-to-date, the trailing week and the sparkline,
     * so the header never asks the same table four times.
     *
     * @return array<string, float>
     */
    private function dailyCost(int $userId, Carbon $from, Carbon $to): array
    {
        $rows = TokenUsage::query()->toBase()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(created_at) as day, model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, SUM(cached_tokens) as cached')
            ->groupBy('day', 'model')
            ->get();

        $daily = [];
        foreach ($rows as $row) {
            $day = (string) $row->day;
            $daily[$day] = ($daily[$day] ?? 0.0)
                + $this->costs->costFor((string) $row->model, (int) $row->prompt, (int) $row->completion, (int) $row->cached);
        }

        return $daily;
    }

    /**
     * @param  array<string, float>  $daily
     * @return list<array{day:string, cost:float}>
     */
    private function sparkline(array $daily, Carbon $today): array
    {
        $out = [];
        for ($offset = 29; $offset >= 0; $offset--) {
            $day = $today->copy()->subDays($offset)->toDateString();
            $out[] = ['day' => $day, 'cost' => $daily[$day] ?? 0.0];
        }

        return $out;
    }

    /** @param array<string, float> $daily */
    private function sumBetween(array $daily, Carbon $from, Carbon $to): float
    {
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();

        $total = 0.0;
        foreach ($daily as $day => $cost) {
            if ($day >= $fromDay && $day <= $toDay) {
                $total += $cost;
            }
        }

        return $total;
    }
}
