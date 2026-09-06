<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;

/**
 * Records the one clamp outcome that has to outlive the render: a today
 * downgraded all the way to a full rest.
 *
 * {@see ReadinessClamp} is otherwise deliberately render-only, and that works
 * because every other consumer recomputes it. Compliance cannot — it runs the
 * next morning, and the ceiling is derived from
 * {@see TrainingLoad::summary()}, which counts the day's own runs, so the
 * ceiling that produced an 08:00 clamp no longer exists at 00:03. Without a
 * record, an athlete who took the rest the card prescribed is graded against
 * the session it replaced and scores `missed` for complying.
 *
 * Called wherever the ceiling is already being computed — the ingest listener
 * and the daily briefing — never from a render, so a GET never writes. Write
 * once and never cleared: readiness recovering later in the day does not
 * un-tell the athlete to rest, and excusing is the forgiving direction.
 */
final readonly class RestClampRecorder
{
    public function __construct(private TrainingLoad $trainingLoad)
    {
    }

    public function record(User $user, Carbon $today): bool
    {
        $session = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $today->toDateString())
            ->first();

        // A pinned row is exempt from the clamp at render time too, so it must
        // not be excused by one here either.
        if ($session === null || $session->pinned || $session->rest_clamped_at !== null) {
            return false;
        }

        $ceiling = ReadinessCeiling::from(
            BriefingContext::forUser($user, $today, $this->trainingLoad->summary($user, $today))->readinessCeiling,
        );

        if (! ReadinessClamp::clampsToRest($session->session_type, $ceiling)) {
            return false;
        }

        $session->update(['rest_clamped_at' => Carbon::now()]);

        return true;
    }
}
