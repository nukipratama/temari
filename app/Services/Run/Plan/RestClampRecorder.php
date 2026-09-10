<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\DayClampedNotification;
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
     * The week's volume multiplier, read the same way {@see CurrentWeekPlanBuilder}
     * and {@see PlanPageAssembler} do — the same trailing
     * window resolves to the same multiplier for the shared week. Needed so
     * the eased distance recorded here matches the one the render actually
     * showed: {@see ReadinessClamp::apply()}'s core_km scales with it, and a
     * hardcoded 1.0 would only agree with the render by coincidence, in the
     * one phase where the ramp has not moved off it yet.
     */
    private function volumeMultiplierFor(User $user, Carbon $today): float
    {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [
                $currentWeekStart->copy()->subWeeks(CurrentWeekPlanBuilder::HISTORY_WEEKS)->toDateString(),
                $currentWeekStart->copy()->addDays(6)->toDateString(),
            ])
            ->orderBy('date')
            ->get();

        if ($sessions->isEmpty()) {
            return 1.0;
        }

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );
        [, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);

        return $multiplierByWeek[$currentWeekStart->toDateString()] ?? 1.0;
    }

    public function record(User $user, Carbon $today): bool
    {
        $session = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $today->toDateString())
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
            $this->tell($user, $today, SessionType::Rest, $session->session_type, $ceiling);

            return true;
        }

        // `Readiness::assess()` caps to `EasyOnly` on `ranToday` alone, so after
        // any run at all the ceiling reads easy — including on a day the
        // athlete just correctly ran a tempo. Recording an eased target then
        // would tell the scorer the day only ever asked for 3.6 km, and grade a
        // properly-executed 6 km tempo as an overreach. A cap caused by having
        // already trained is guidance for a SECOND outing, never an instruction
        // that replaced the session, so it is not a target anyone was set. See
        // `docs/decisions/a-clamped-day-is-graded-on-what-it-asked.md`.
        if ($context->ranToday) {
            return false;
        }

        // core_km comes from the same ReadinessClamp::apply() the render calls
        // (paces are irrelevant to it, so null is safe) rather than a hand-rolled
        // SegmentGenerator::coreKmFor(): apply()'s ModerateOk arm sizes a downgraded
        // Tempo/Interval by the ORIGINAL session type, not by SessionType::Easy, and
        // its EasyOnly arm sizes a downgraded Long day by the primary-easy fraction —
        // two distinctions a hardcoded (Easy, isPrimaryEasy: false) call collapses.
        $clamp = ReadinessClamp::apply(
            $session->session_type,
            $session->phase,
            $session->race_distance_m === null ? null : (float) $session->race_distance_m,
            (float) $this->baseline->forUser($user, $today)['long_run_km'],
            $this->volumeMultiplierFor($user, $today),
            null,
            $ceiling,
        );
        if ($clamp === null) {
            return false;
        }

        $session->update(['clamped_km' => $clamp['core_km']]);
        $this->tell($user, $today, $clamp['session_type'], $session->session_type, $ceiling);

        return true;
    }

    /**
     * The athlete's copy of what was just recorded. Sent from here rather than
     * from the two call sites because the guards above make this the one place
     * that fires once per athlete per day; a step-down otherwise existed only
     * while the plan page still rendered it.
     */
    private function tell(User $user, Carbon $today, SessionType $clampedTo, SessionType $original, ReadinessCeiling $ceiling): void
    {
        $note = ReadinessClamp::noteFor($original, $ceiling);
        if ($note === null) {
            return;
        }

        $user->notify(new DayClampedNotification($today->toDateString(), $clampedTo, $note));
    }
}
