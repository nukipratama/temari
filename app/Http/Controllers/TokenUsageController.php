<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\TokenUsageReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TokenUsageController extends Controller
{
    public function __construct(private readonly TokenUsageReport $report)
    {
    }

    public function show(Request $request): Response
    {
        $validated = $request->validate([
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d',
            'kind' => 'sometimes|string',
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : Carbon::today()->startOfWeek(Carbon::MONDAY);
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : Carbon::now();
        $kind = $validated['kind'] ?? null;

        $report = $this->report->build($from, $to, $kind);

        return Inertia::render('AiUsage', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kind' => $kind,
            'totals' => $report['totals'],
            'byKind' => $report['byKind'],
            'byUser' => $report['byUser'],
            'byDeployment' => $report['byDeployment'],
            'daily' => $report['daily'],
            'availableKinds' => $report['availableKinds'],
            'budget' => $report['budget'],
        ]);
    }
}
