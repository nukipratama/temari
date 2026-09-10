<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowNarrationOverviewRequest;
use App\Services\AI\AnalysisService;
use App\Services\AI\TokenUsageReport;
use App\Services\Devtools\DevtoolsActionRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class NarrationOverviewController extends Controller
{
    public function __construct(
        private readonly TokenUsageReport $report,
        private readonly AnalysisService $analysisService,
        private readonly DevtoolsActionRecorder $recorder,
    ) {
    }

    public function show(ShowNarrationOverviewRequest $request): Response
    {
        $validated = $request->validated();

        [$range, $from, $to] = $this->resolveRange($validated);
        $kind = $validated['kind'] ?? null;
        $origin = $validated['origin'] ?? null;
        $athlete = isset($validated['athlete']) ? (int) $validated['athlete'] : null;

        $report = $this->report->build($from, $to, $kind, includePrevious: $range !== 'all', origin: $origin);
        $athletes = $this->report->athletes($from, $to);

        return Inertia::render('Narration/Overview', [
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kind' => $kind,
            'origin' => $origin,
            'athlete' => $athlete,
            'totals' => $report['totals'],
            'previousTotals' => $report['previousTotals'],
            'byKind' => $report['byKind'],
            'byDeployment' => $report['byDeployment'],
            'byOrigin' => $report['byOrigin'],
            'availableKinds' => $report['availableKinds'],
            'availableOrigins' => $report['availableOrigins'],
            'budget' => $report['budget'],
            'contentFilter' => $report['contentFilter'],
            'chart' => $this->report->dailyCostByKind($from, $to, $athlete),
            'athletes' => $athletes,
            'cappedToday' => count(array_filter($athletes, fn (array $row): bool => $row['capped'])),
            'pauseReason' => $this->analysisService->pauseReason(),
        ]);
    }

    /**
     * One-shot post-outage recovery: re-arm every dead-lettered block across users
     * and run the full self-heal sweep immediately, instead of an N-click,
     * up-to-60-min-cadence scavenger hunt. Admin-gated by the route.
     */
    public function recover(): RedirectResponse
    {
        Artisan::call('ai:recover');
        $this->recorder->record('narration.recover');

        return back()->with('info', 'Recovery ran: dead-lettered blocks were retried and self-heal swept immediately.');
    }

    /**
     * Resolve the relative range token to concrete dates on every request, so
     * preset links stay correct as the calendar rolls (a baked-in absolute
     * `to` would silently point at yesterday). Bare requests default to the
     * rolling last 7 days; legacy absolute `from`+`to` links (no `range`) map
     * to a `custom` range for back-compat.
     *
     * @param  array{range?:string, from?:string, to?:string}  $validated
     * @return array{0:string, 1:Carbon, 2:Carbon}
     */
    private function resolveRange(array $validated): array
    {
        $hasCustomDates = isset($validated['from'], $validated['to']);
        $range = $validated['range'] ?? ($hasCustomDates ? 'custom' : '7d');
        if ($range === 'custom' && ! $hasCustomDates) {
            $range = '7d';
        }

        $sevenDaysAgo = Carbon::today()->subDays(6)->startOfDay();

        [$from, $to] = match ($range) {
            'today' => [Carbon::today()->startOfDay(), Carbon::now()],
            '7d' => [$sevenDaysAgo, Carbon::now()],
            '30d' => [Carbon::today()->subDays(29)->startOfDay(), Carbon::now()],
            'month' => [Carbon::now()->startOfMonth(), Carbon::now()],
            'all' => [Carbon::createFromTimestamp(0), Carbon::now()],
            'custom' => [Carbon::parse($validated['from'])->startOfDay(), Carbon::parse($validated['to'])->endOfDay()],
            default => [$sevenDaysAgo, Carbon::now()],
        };

        return [$range, $from, $to];
    }
}
