<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TokenUsageController extends Controller
{
    public function show(Request $request): Response
    {
        $validated = $request->validate([
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d',
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : Carbon::today()->startOfMonth();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : Carbon::now();

        $rows = DB::table('ai_token_usages')
            ->whereBetween('created_at', [$from, $to])
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

        $userRows = DB::table('ai_token_usages')
            ->leftJoin('users', 'users.id', '=', 'ai_token_usages.user_id')
            ->whereBetween('ai_token_usages.created_at', [$from, $to])
            ->whereNotNull('ai_token_usages.user_id')
            ->selectRaw(
                'ai_token_usages.user_id as user_id, users.name as user_name, '.
                'SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, '.
                'SUM(total_tokens) as total, COUNT(*) as calls'
            )
            ->groupBy('ai_token_usages.user_id', 'users.name')
            ->orderByDesc('total')
            ->get();

        $byUser = [];
        foreach ($userRows as $row) {
            $byUser[] = [
                'user_id' => (int) $row->user_id,
                'user_name' => $row->user_name === null ? null : (string) $row->user_name,
                'prompt' => (int) $row->prompt,
                'completion' => (int) $row->completion,
                'total' => (int) $row->total,
                'calls' => (int) $row->calls,
            ];
        }

        return Inertia::render('AiUsage', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'byKind' => $byKind,
            'byUser' => $byUser,
        ]);
    }
}
