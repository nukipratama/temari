<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\AnalysisType;
use App\Services\AI\CeilingOverride;
use App\Services\Devtools\AthleteNarrationReport;
use App\Services\Devtools\DevtoolsActionRecorder;
use App\Services\Devtools\ReArmNarrationAction;
use App\Services\Devtools\ReplayNarrationAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One athlete's narration: what it cost, what it said, and what is stuck.
 * Everything here is scoped to that athlete, which is what separates it from the
 * /devtools/narration overview.
 *
 * Athletes are bound by raw id rather than by model binding on the POSTs, so a
 * block whose owning row is gone stays actionable, matching the ai-usage re-arm.
 */
class NarrationAthleteController extends Controller
{
    public function __construct(
        private readonly AthleteNarrationReport $report,
        private readonly DevtoolsActionRecorder $recorder,
        private readonly ReArmNarrationAction $reArm,
        private readonly ReplayNarrationAction $replayAction,
        private readonly CeilingOverride $override,
    ) {
    }

    public function show(Request $request, int $userId): Response
    {
        $athlete = User::query()->findOrFail($userId);

        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['narrations', 'cost', 'attention'])],
            'kind' => ['nullable', Rule::enum(AnalysisType::class)],
            'status' => ['nullable', Rule::enum(AnalysisStatus::class)],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);

        $kind = isset($validated['kind']) ? AnalysisType::from($validated['kind']) : null;
        $status = isset($validated['status']) ? AnalysisStatus::from($validated['status']) : null;
        $before = isset($validated['before']) ? (int) $validated['before'] : null;

        $narrations = $this->report->narrations($athlete->id, $kind, $status, $before);

        return Inertia::render('Narration/Athlete', [
            'tab' => $validated['tab'] ?? 'narrations',
            'filters' => [
                'kind' => $kind?->value,
                'status' => $status?->value,
                'before' => $before,
            ],
            'availableKinds' => array_map(fn (AnalysisType $type): string => $type->value, AnalysisType::cases()),
            'availableStatuses' => array_map(fn (AnalysisStatus $s): string => $s->value, AnalysisStatus::cases()),
            'header' => $this->report->header($athlete),
            'narrations' => $narrations['rows'],
            'nextCursor' => $narrations['next_cursor'],
            'costByKind' => $this->report->costByKind($athlete->id),
            'attention' => $this->report->attention($athlete->id),
            'audit' => $this->report->audit($athlete->id),
            'override' => $this->report->activeOverride($athlete->id),
            'replayBudget' => [
                'cap' => $this->replayAction->cap(),
                'spent_today' => $this->replayAction->spentToday(),
                'cap_reached' => $this->replayAction->capReached(),
            ],
        ]);
    }

    public function retryFailed(int $userId): RedirectResponse
    {
        $count = $this->reArm->retryFailed($userId);
        $this->recorder->record('narration.athlete.retry_failed', $userId, ['blocks' => $count]);

        return back()->with('info', "retrying {$count} failed block(s).");
    }

    public function reArm(int $userId): RedirectResponse
    {
        $count = $this->reArm->reArmDeadLettered($userId);
        $this->recorder->record('narration.athlete.re_arm', $userId, ['blocks' => $count]);

        return back()->with('info', "re-armed {$count} dead-lettered block(s).");
    }

    public function resync(int $userId): RedirectResponse
    {
        SyncActivitiesJob::dispatch($userId);
        $this->recorder->record('narration.athlete.resync', $userId);

        return back()->with('info', 'queued a full Strava sync for this athlete.');
    }

    public function setCeiling(Request $request, int $userId): RedirectResponse
    {
        $validated = $request->validate([
            'ceiling' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $ceiling = (float) $validated['ceiling'];
        $this->override->set($userId, $ceiling);
        $this->recorder->record('narration.athlete.ceiling_override', $userId, ['ceiling' => $ceiling]);

        return back()->with('info', "today's ceiling for this athlete is now \${$ceiling}.");
    }

    public function clearCeiling(int $userId): RedirectResponse
    {
        $this->override->clear($userId);
        $this->recorder->record('narration.athlete.ceiling_override_cleared', $userId);

        return back()->with('info', 'ceiling override cleared, the configured slice applies again.');
    }

    public function replay(Request $request, int $userId): RedirectResponse
    {
        $athlete = User::query()->findOrFail($userId);

        $validated = $request->validate([
            'analysis_id' => ['required', 'integer'],
        ]);

        $row = AnalysisSubjectMap::whereOwnedBy(Analysis::query()->knownType(), $userId)
            ->where('ai_analyses.id', (int) $validated['analysis_id'])
            ->firstOrFail();

        if ($this->replayAction->capReached()) {
            return back()->with(
                'info',
                'replay refused: today\'s replay budget is spent. it resets at midnight.',
            );
        }

        $this->replayAction->replay($row, $athlete);
        $this->recorder->record('narration.athlete.replay', $userId, [
            'analysis_id' => $row->id,
            'kind' => $row->analysis_type->value,
        ]);

        return back()->with('info', 'replay queued, the new narration lands here when it is done.');
    }
}
