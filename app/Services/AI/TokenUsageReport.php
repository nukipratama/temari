<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the /ai-usage reporting payload from the analytics-schema
 * `ai_token_usages` table: totals, per-kind, per-user, daily, and the kind
 * filter options. user_id lives in the analytics schema while user names live
 * in the app schema, so per-user rows are aggregated first and stitched to
 * names in PHP to avoid a fragile cross-schema join.
 */
class TokenUsageReport
{
    /**
     * @return array{
     *     totals: array{prompt:int, completion:int, total:int, calls:int, truncated_calls:int},
     *     byKind: list<array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_latency_ms:int|null, max_latency_ms:int|null}>,
     *     byUser: list<array{user_id:int, user_name:string|null, prompt:int, completion:int, total:int, calls:int}>,
     *     daily: list<array{day:string, prompt:int, completion:int, total:int, calls:int}>,
     *     availableKinds: list<array{value:string, label:string}>,
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

        return [
            'totals' => $totalsAndByKind['totals'],
            'byKind' => $totalsAndByKind['byKind'],
            'byUser' => $this->byUser($baseQuery),
            'daily' => $this->daily($from, $to),
            'availableKinds' => $this->availableKinds($from, $to),
        ];
    }

    /**
     * @param  Builder  $baseQuery
     * @return array{totals: array{prompt:int, completion:int, total:int, calls:int, truncated_calls:int}, byKind: list<array{kind:string, prompt:int, completion:int, total:int, calls:int, truncated_calls:int, avg_latency_ms:int|null, max_latency_ms:int|null}>}
     */
    private function totalsAndByKind(Builder $baseQuery): array
    {
        $rows = (clone $baseQuery)
            ->selectRaw(
                'kind, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls, '.
                'SUM(CASE WHEN truncated = 1 THEN 1 ELSE 0 END) as truncated_calls, '.
                'AVG(latency_ms) as avg_latency_ms, MAX(latency_ms) as max_latency_ms'
            )
            ->groupBy('kind')
            ->orderByDesc('total')
            ->get();

        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0, 'truncated_calls' => 0];
        $byKind = [];
        foreach ($rows as $row) {
            $entry = [
                'kind' => (string) $row->kind,
                'prompt' => (int) $row->prompt,
                'completion' => (int) $row->completion,
                'total' => (int) $row->total,
                'calls' => (int) $row->calls,
                'truncated_calls' => (int) $row->truncated_calls,
                'avg_latency_ms' => $row->avg_latency_ms === null ? null : (int) round((float) $row->avg_latency_ms),
                'max_latency_ms' => $row->max_latency_ms === null ? null : (int) $row->max_latency_ms,
            ];
            $byKind[] = $entry;
            $totals['prompt'] += $entry['prompt'];
            $totals['completion'] += $entry['completion'];
            $totals['total'] += $entry['total'];
            $totals['calls'] += $entry['calls'];
            $totals['truncated_calls'] += $entry['truncated_calls'];
        }

        return ['totals' => $totals, 'byKind' => $byKind];
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
     * shows the full picture regardless of the kind filter.
     *
     * @return list<array{day:string, prompt:int, completion:int, total:int, calls:int}>
     */
    private function daily(Carbon $from, Carbon $to): array
    {
        $dailyRows = DB::connection('analytics')->table('ai_token_usages')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                'DATE(created_at) as day, '.
                'SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls'
            )
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get();

        $daily = [];
        foreach ($dailyRows as $row) {
            $daily[] = [
                'day' => (string) $row->day,
                'prompt' => (int) $row->prompt,
                'completion' => (int) $row->completion,
                'total' => (int) $row->total,
                'calls' => (int) $row->calls,
            ];
        }

        return $daily;
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
