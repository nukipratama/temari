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
            ->selectRaw('kind, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, SUM(total_tokens) as total, COUNT(*) as calls')
            ->groupBy('kind')
            ->orderByDesc('total')
            ->get();

        $totals = ['prompt' => 0, 'completion' => 0, 'total' => 0, 'calls' => 0];
        $byKind = [];
        foreach ($rows as $row) {
            $entry = [
                'kind' => (string) $row->kind,
                'prompt' => (int) $row->prompt,
                'completion' => (int) $row->completion,
                'total' => (int) $row->total,
                'calls' => (int) $row->calls,
            ];
            $byKind[] = $entry;
            $totals['prompt'] += $entry['prompt'];
            $totals['completion'] += $entry['completion'];
            $totals['total'] += $entry['total'];
            $totals['calls'] += $entry['calls'];
        }

        return Inertia::render('AiUsage', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'byKind' => $byKind,
        ]);
    }
}
