<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShowNarrationOverviewRequest;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\NarrationOrigin;
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
     * Placeholder for the per-athlete page, so the athlete rows can already link
     * by route name. Replaced by the real controller in the athlete slice.
     */
    public function athlete(int $userId): never
    {
        abort(404);
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
     * Re-arm and re-dispatch every Failed block for one user, whether dead-lettered
     * or still under budget. Resetting attempts to 0 restores the self-heal budget,
     * and invalidate:false re-dispatches without re-billing any Done siblings.
     * Cost-safe even mid-cap: the job-level guard reverts to Pending. Powers the
     * per-athlete retry button on the narration overview's athlete table.
     *
     * Binds by raw id rather than implicit `User` model binding: a hard-deleted
     * user's user-keyed `ai_analyses` rows survive (no FK), so their group must
     * stay retryable even when the `users` row is gone. The user model is only
     * needed for the flash message's display name.
     */
    public function retryFailed(int $userId): RedirectResponse
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Recovery);

        $matching = AnalysisSubjectMap::whereOwnedBy(
            Analysis::query()->knownType()->where('status', AnalysisStatus::Failed),
            $userId,
        )->get();

        foreach ($matching as $row) {
            $row->update(['attempts' => 0]);
            $this->analysisService->request(
                subjectOrType: $row->subject_type,
                subjectId: $row->subject_id,
                type: $row->analysis_type,
                discriminator: $row->discriminator,
                invalidate: false,
            );
        }

        $this->recorder->record('narration.retry_failed', $userId, ['blocks' => $matching->count()]);

        $userName = User::query()->find($userId)?->name ?? "User #{$userId}";

        return back()->with('info', "Retrying {$matching->count()} block(s) for {$userName}.");
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
