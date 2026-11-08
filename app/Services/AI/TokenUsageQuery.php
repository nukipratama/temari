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
use Illuminate\Support\Collection;
use stdClass;

/**
 * The reads behind the /devtools/narration report: raw aggregates off the analytics-schema
 * `ai_token_usages` table and the per-athlete counts off the app schema. Each side of the schema
 * split is read on its own and stitched in PHP by {@see TokenUsageReport}, to avoid a cross-schema join.
 */
class TokenUsageQuery
{
    /**
     * @return Collection<int, stdClass>
     */
    public function kindModelRows(Carbon $from, Carbon $to, ?string $kind, ?string $origin): Collection
    {
        return $this->scoped($from, $to, $kind, $origin)
            ->selectRaw(
                'kind, model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls, '.
                'SUM(cached_tokens) as cached, SUM(reasoning_tokens) as reasoning, SUM(steps) as steps, '.
                'SUM(CASE WHEN truncated = 1 THEN 1 ELSE 0 END) as truncated_calls, '.
                'AVG(latency_ms) as avg_latency_ms, MAX(latency_ms) as max_latency_ms'
            )
            ->groupBy('kind', 'model')
            ->get();
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function originModelRows(Carbon $from, Carbon $to, ?string $kind, ?string $origin): Collection
    {
        return $this->scoped($from, $to, $kind, $origin)
            ->selectRaw(
                'origin, model, SUM(prompt_tokens) AS prompt, SUM(cached_tokens) AS cached, '
                .'SUM(completion_tokens) AS completion, SUM(total_tokens) AS total, COUNT(*) AS calls'
            )
            ->groupBy('origin', 'model')
            ->get();
    }

    public function todayTokens(): ?stdClass
    {
        return TokenUsage::query()->toBase()
            ->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
            ->selectRaw(
                'SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(cached_tokens) as cached, SUM(total_tokens) as total'
            )
            ->first();
    }

    public function contentFilterTrips(Carbon $from, Carbon $to): int
    {
        return ContentFilterEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    public function athleteCount(): int
    {
        return User::query()->notDemo()->count();
    }

    /**
     * @return Collection<int, string>
     */
    public function origins(Carbon $from, Carbon $to): Collection
    {
        /** @var Collection<int, string> $origins */
        $origins = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->orderBy('origin')
            ->pluck('origin');

        return $origins;
    }

    /**
     * @return Collection<int, string>
     */
    public function kinds(Carbon $from, Carbon $to): Collection
    {
        /** @var Collection<int, string> $kinds */
        $kinds = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$from, $to])
            ->distinct()
            ->orderBy('kind')
            ->pluck('kind');

        return $kinds;
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function dailyKindModelRows(Carbon $from, Carbon $to, ?int $userId): Collection
    {
        return TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$from, $to])
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('user_id', $userId))
            ->selectRaw(
                'DATE(created_at) as day, kind, model, SUM(prompt_tokens) as prompt, '.
                'SUM(completion_tokens) as completion, SUM(cached_tokens) as cached'
            )
            ->groupByRaw('DATE(created_at), kind, model')
            ->orderBy('day')
            ->get();
    }

    /**
     * @return Collection<int, stdClass>
     */
    public function athleteSpendRows(Carbon $windowStart): Collection
    {
        return TokenUsage::query()->toBase()
            ->where('created_at', '>=', $windowStart)
            ->whereNotNull('user_id')
            ->selectRaw(
                'user_id, DATE(created_at) as day, model, MAX(user_name) as snapshot_name, '.
                'SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(cached_tokens) as cached, COUNT(*) as calls'
            )
            ->groupByRaw('user_id, DATE(created_at), model')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function users(): Collection
    {
        return User::query()->orderBy('id')->get(['id', 'name', 'is_demo', 'last_seen_at']);
    }

    /**
     * Done narrations in range with an owner, as one row each.
     *
     * @return list<array{user_id:int, served_by:ServedBy|null, rule_based_reason:AnalysisOrigin|null}>
     */
    public function doneNarrations(Carbon $from, Carbon $to): array
    {
        $rows = Analysis::query()
            ->where('status', AnalysisStatus::Done)
            ->whereBetween('generated_at', [$from, $to])
            ->get(['id', 'subject_type', 'subject_id', 'served_by', 'rule_based_reason']);

        $owners = AnalysisSubjectMap::ownerIdsForRows($rows);

        $narrations = [];
        foreach ($rows as $row) {
            $userId = $owners[$row->id] ?? null;
            if ($userId === null) {
                continue;
            }

            $narrations[] = [
                'user_id' => $userId,
                'served_by' => $row->served_by,
                'rule_based_reason' => $row->rule_based_reason,
            ];
        }

        return $narrations;
    }

    /**
     * @return array<int, int>
     */
    public function flagCounts(Carbon $from, Carbon $to): array
    {
        /** @var Collection<int, int|string> $flags */
        $flags = Feedback::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('user_id, COUNT(*) as flags')
            ->groupBy('user_id')
            ->pluck('flags', 'user_id');

        return $flags->map(fn (int|string $count): int => (int) $count)->all();
    }

    /**
     * @return array<int, int>
     */
    public function deadLetterCounts(): array
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

    private function scoped(Carbon $from, Carbon $to, ?string $kind, ?string $origin): Builder
    {
        $query = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [$from, $to]);

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        if ($origin !== null) {
            $query->where('origin', $origin);
        }

        return $query;
    }
}
