<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\IngestState;
use App\Enums\PerformanceEvidenceKind;
use App\Enums\TimeTrialOutcome;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\TimeTrialNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What a finished time trial becomes: evidence at once when a run on its day
 * passes the gate, otherwise one question to the athlete. A trial that was
 * excused, eased or not run is left to the plan's single retry, and one whose
 * run is still only a summary waits for a later settle within the lookback.
 */
final readonly class TimeTrialService
{
    public function __construct(
        private PerformanceEvidenceRecorder $evidence,
        private ComplianceScorer $compliance,
    ) {
    }

    public function settle(PlannedSession $session): ?TimeTrialOutcome
    {
        $trial = TimeTrial::of($session);
        if ($trial === null || $session->time_trial_outcome !== null) {
            return null;
        }

        $runs = $this->runsOn($session, $trial);
        if ($this->awaitsHydration($runs) || TimeTrial::countsAsSkipped($session, $runs->isNotEmpty())) {
            return null;
        }

        $zones = $session->user->hrProfile()['hr_zones'];
        $passing = $runs->first(static fn (ActivityDetail $run): bool => $trial->passes($trial->reading($run), $zones) !== null);
        if ($passing !== null && $this->countsPlausibly($session, $trial, $passing)) {
            return $this->mark($session, TimeTrialOutcome::Confirmed);
        }

        $this->mark($session, TimeTrialOutcome::Asked);
        $session->user->notify(new TimeTrialNotification($session));

        return TimeTrialOutcome::Asked;
    }

    public function answer(User $user, PlannedSession $session, bool $allOut): TimeTrialOutcome
    {
        if ($session->user_id !== $user->id) {
            throw new AuthorizationException();
        }
        $trial = TimeTrial::of($session);
        if ($trial === null || $session->time_trial_outcome !== TimeTrialOutcome::Asked) {
            throw ValidationException::withMessages(['answer' => 'This trial is already settled.']);
        }
        if (! $allOut) {
            return $this->mark($session, TimeTrialOutcome::Declined);
        }

        $run = $this->runsOn($session, $trial)->first();
        if ($run === null || ! $this->count($session, $trial, $run)) {
            throw ValidationException::withMessages(['answer' => 'There is no run on that day that can count.']);
        }

        $this->mark($session, TimeTrialOutcome::Confirmed);
        $verdict = $this->compliance->verdictsFor($user, Collection::wrap([$session]), Carbon::today())[$session->date->toDateString()] ?? null;
        if ($verdict !== null) {
            ComplianceScorer::applyVerdict($session, $verdict);
        }

        return TimeTrialOutcome::Confirmed;
    }

    /** @return Collection<int, ActivityDetail> */
    private function runsOn(PlannedSession $session, TimeTrial $trial): Collection
    {
        return ActivityDetail::query()->forUser($session->user_id)
            ->whereDate('activity_details.start_date_local', $session->date->toDateString())
            ->orderByRaw('ABS(activity_details.distance - ?) asc', [$trial->distanceM])
            ->orderByDesc('activity_details.distance')
            ->orderBy('activity_details.activity_id')
            ->get();
    }

    /** @param  Collection<int, ActivityDetail>  $runs */
    private function awaitsHydration(Collection $runs): bool
    {
        return Activity::query()
            ->whereKey($runs->pluck('activity_id')->all())
            ->where('ingest_state', IngestState::Summary)
            ->exists();
    }

    private function countsPlausibly(PlannedSession $session, TimeTrial $trial, ActivityDetail $run): bool
    {
        try {
            return $this->count($session, $trial, $run);
        } catch (ValidationException) {
            return false;
        }
    }

    private function count(PlannedSession $session, TimeTrial $trial, ActivityDetail $run): bool
    {
        $reading = $trial->reading($run);
        if ($reading['time_sec'] === null || ! PerformanceEvidenceRecorder::qualifies($reading['distance_m'])) {
            return false;
        }

        $this->evidence->record($session->user, [
            'kind' => PerformanceEvidenceKind::Test->value,
            'distance_m' => (int) round($reading['distance_m']),
            'elapsed_time_sec' => (int) round($reading['time_sec']),
            'performed_on' => $session->date->toDateString(),
            'activity_id' => $run->activity_id,
        ]);

        return true;
    }

    private function mark(PlannedSession $session, TimeTrialOutcome $outcome): TimeTrialOutcome
    {
        $session->update(['time_trial_outcome' => $outcome]);

        return $outcome;
    }
}
