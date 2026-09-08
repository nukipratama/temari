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
 * Records the clamp outcomes that have to outlive the render: a today
 * downgraded to a full rest, and the eased distance a lighter downgrade asked
 * for instead.
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
    public function __construct(
        private TrainingLoad $trainingLoad,
        private TrainingBaseline $baseline,
    ) {
    }

    /**
     * Whether the eased distance is safe to record, which turns entirely on why
     * the ceiling dropped.
     *
     * `Readiness::assess()` caps to `EasyOnly` on `ranToday` alone, so after any
     * run at all the ceiling reads easy — including on a day the athlete just
     * correctly ran a tempo. Recording an eased target then would tell the
     * scorer the day only ever asked for 3.6 km, and grade a properly-executed
     * 6 km tempo as an overreach. A cap caused by having already trained is
     * guidance for a SECOND outing, never an instruction that replaced the
     * session, so it is not a target anyone was set. See
     * `docs/decisions/a-clamped-day-is-graded-on-what-it-asked.md`.
     */
    private static function isReplacementTarget(bool $ranToday): bool
    {
        return ! $ranToday;
    }

    public function record(User $user, Carbon $today): bool
    {
        $session = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $today->toDateString())
            ->first();

        // A pinned row is exempt from the clamp at render time too, so it must
        // not be excused by one here either.
        if ($session === null || $session->pinned || $session->rest_clamped_at !== null || $session->clamped_km !== null) {
            return false;
        }

        // The ceiling has to reflect the activity that just triggered this call
        // (the ingest listener's whole reason to run) or a carried-over cache
        // from an earlier dashboard load this same window would compute against
        // stale, pre-ingest load — and unlike a render, this write never
        // self-corrects.
        TrainingLoad::clearSummaryCache($user);

        $context = BriefingContext::forUser($user, $today, $this->trainingLoad->summary($user, $today));
        $ceiling = ReadinessCeiling::from($context->readinessCeiling);

        if (ReadinessClamp::clampsToRest($session->session_type, $ceiling)) {
            $session->update(['rest_clamped_at' => Carbon::now()]);

            return true;
        }

        $easedTo = ReadinessClamp::downgradeFor($session->session_type, $ceiling);
        if ($easedTo === null || ! self::isReplacementTarget($context->ranToday)) {
            return false;
        }

        $session->update([
            'clamped_km' => SegmentGenerator::coreKmFor(
                $easedTo,
                false,
                (float) $this->baseline->forUser($user, $today)['long_run_km'],
                1.0,
            ),
        ]);

        return true;
    }
}
