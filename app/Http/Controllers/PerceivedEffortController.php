<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Metrics\PerceivedEffort;
use App\Services\Run\Trend\TrendSnapshotRepairDispatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PerceivedEffortController extends Controller
{
    public function __construct(
        private readonly ActivityPipeline $pipeline,
        private readonly TrendSnapshotRepairDispatch $trendSnapshots,
    ) {
    }

    public function update(Request $request, Activity $activity): RedirectResponse
    {
        $detail = $this->openRun($request, $activity);
        $validated = $request->validate([
            'score' => ['required', 'integer', 'between:'.PerceivedEffort::MIN.','.PerceivedEffort::MAX],
        ]);

        return $this->record($activity, $detail, (int) $validated['score']);
    }

    public function destroy(Request $request, Activity $activity): RedirectResponse
    {
        return $this->record($activity, $this->openRun($request, $activity), null);
    }

    private function openRun(Request $request, Activity $activity): ActivityDetail
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

        $detail = $activity->detail;
        abort_if($detail === null, 404);

        if ($detail->has_heartrate) {
            throw ValidationException::withMessages(['score' => 'this run has heart rate, so its load is already counted.']);
        }
        if (! PerceivedEffort::accepts($detail, now())) {
            throw ValidationException::withMessages(['score' => 'an effort score can only be set within 72 hours of the run.']);
        }

        return $detail;
    }

    private function record(Activity $activity, ActivityDetail $detail, ?int $score): RedirectResponse
    {
        DB::transaction(function () use ($activity, $detail, $score): void {
            $detail->update(['perceived_effort' => $score]);
            $activity->setRelation('detail', $detail);
            $this->pipeline->recomputeSummary($activity, reconcileMaxHeartRate: false);
            $this->trendSnapshots->forActivity($activity);
        });

        return back();
    }
}
