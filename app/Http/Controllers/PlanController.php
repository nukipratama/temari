<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanRegenerationReason;
use App\Http\Requests\UpdatePlannedSessionRequest;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\MakeUpService;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanRegenerationService;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\SessionEditRules;
use App\Services\Run\Plan\SessionMatcher;
use App\Support\TrainingDisclaimer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the Plan tab. Every prop comes from {@see PlanPageAssembler}; this
 * controller only decides which of them Inertia defers.
 */
class PlanController extends Controller
{
    public function index(Request $request, PlanPageAssembler $plan): Response
    {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today();

        // Two requests serve this page: the initial render, then Inertia's
        // partial for the deferred props — and the whole action runs again on
        // the second one. Every prop is a closure so the partial resolves only
        // what it asked for.
        return Inertia::render('Plan', [
            'race' => fn (): ?array => $plan->race($user),
            'sessionsPerWeek' => fn (): int => $plan->sessionsPerWeek($user, $today),
            'weeks' => Inertia::defer(fn (): array => $plan->weeks($user, $today)),
            'season' => fn (): ?array => $plan->season($user, $today),
            'seasonSummary' => Inertia::defer(fn (): array => $plan->seasonSummary($user, $today)),
            'seasonAdherencePct' => Inertia::defer(fn (): ?int => $plan->seasonAdherencePct($user, $today)),
            'adaptation' => Inertia::defer(fn (): ?array => $plan->adaptation($user, $today)),
            'disclaimerLine' => TrainingDisclaimer::SHORT,
            'planNarration' => Inertia::defer(fn (): array => $plan->planNarration($user)),
            'regenerateCooldownSeconds' => fn (): ?int => $plan->regenerateCooldownSeconds($user),
        ]);
    }

    public function regenerate(Request $request, PlanNarrationRequester $narrationRequester, PlanRegenerationService $regeneration): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($narrationRequester->regenerateCooldownRemaining($user) !== null) {
            return back()->with('info', "Temari's still catching up on the last replan. Give it a little longer.");
        }

        if (! $regeneration->regenerateForRequest($user, PlanRegenerationReason::Manual)) {
            return back()->with('info', 'The plan is updating right now. Temari will replan it as soon as the current update finishes.');
        }

        return back()->with('success', "Temari's replanned the weeks ahead against where you are now.");
    }

    /**
     * Move (date), skip or restore (excuse the day before it passes), or
     * pin/unpin, each only where {@see SessionEditRules} allows it. Any
     * explicit edit fixes the day (pins it) so the next regeneration doesn't
     * silently overwrite it, unless the caller passes `pinned: false` to hand
     * control back to the periodizer.
     *
     * A move is a **swap** with the rest day it lands on, not a re-date:
     * `planned_sessions` is unique on (user_id, date) and the periodizer
     * materializes all seven days of every week.
     */
    public function update(
        UpdatePlannedSessionRequest $request,
        PlannedSession $plannedSession,
        Periodizer $periodizer,
        SessionMatcher $sessionMatcher,
        MakeUpService $makeUps,
        AnalysisService $analysisService,
    ): RedirectResponse {
        $this->authorizeOwner($request, $plannedSession);

        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();
        if (! array_key_exists('pinned', $attributes)) {
            $attributes['pinned'] = true;
        }

        $today = Carbon::today();

        try {
            [$session, $occupant, $makeUpTarget] = $periodizer->withRegenerationLock(
                $user,
                fn (): array => DB::transaction(function () use ($user, $plannedSession, $attributes, $today, $sessionMatcher, $makeUps): array {
                    $session = PlannedSession::query()
                        ->where('user_id', $user->id)
                        ->whereDate('date', $plannedSession->date->toDateString())
                        ->lockForUpdate()
                        ->first();

                    if ($session === null || $session->id !== $plannedSession->id) {
                        abort(409, 'This plan changed while you were editing. Reload and try again.');
                    }

                    if (array_key_exists('skipped', $attributes) && ! SessionEditRules::canToggleSkip($session, $session->status, $today)) {
                        throw ValidationException::withMessages(['skipped' => 'only an unrun day from today on can be skipped or restored.']);
                    }

                    $occupant = null;
                    $makeUpTarget = null;
                    if (isset($attributes['date'])) {
                        [$occupant, $madeUp] = $this->moveTarget($user, $session, Carbon::parse($attributes['date']), $today, $sessionMatcher);
                        $this->swapSessions($session, $occupant);
                        unset($attributes['date']);
                        $makeUpTarget = $madeUp ? $occupant : null;
                    }

                    $session->update($attributes);
                    if ($makeUpTarget !== null) {
                        $makeUps->apply($user, $session, $makeUpTarget, $today);
                    }

                    return [$session, $occupant, $makeUpTarget];
                }),
                Periodizer::REQUEST_LOCK_WAIT_SECONDS,
            );
        } catch (LockTimeoutException) {
            return back()->with('info', 'The plan is updating right now. Reload and try your edit again.');
        }

        if ($makeUpTarget !== null) {
            $makeUps->notify($user, $session->date, $makeUpTarget->date, $today);

            return back();
        }

        $movedOntoOrOffToday = $occupant !== null && ($session->date->isSameDay($today) || $occupant->date->isSameDay($today));
        $toggledToday = $session->date->isSameDay($today) && $session->wasChanged('skipped');
        if (! $user->is_demo && ($movedOntoOrOffToday || $toggledToday)) {
            $analysisService->requestBriefing($user, $today->toDateString(), invalidate: true);
        }

        return back();
    }

    /**
     * @return array{PlannedSession, bool} the rest day the session lands on, and whether the move is a make-up
     */
    private function moveTarget(User $user, PlannedSession $session, Carbon $toDate, Carbon $today, SessionMatcher $sessionMatcher): array
    {
        [$from, $to] = SessionEditRules::window($session->date);
        $rows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->lockForUpdate()
            ->get();
        $ranDates = array_map(strval(...), array_keys($sessionMatcher->activityByDate($user, $from, $to)));
        $target = $rows->first(static fn (PlannedSession $row): bool => $row->date->isSameDay($toDate));

        if ($target === null
            || ! SessionEditRules::canMoveFrom($session, $session->status, $today)
            || ! in_array($target->date->toDateString(), SessionEditRules::moveTargets($session, $rows, $ranDates, $today), true)) {
            throw ValidationException::withMessages(['date' => "this session can't move to that day."]);
        }

        return [$target, SessionEditRules::isMakeUp($session, $target, $ranDates, $today)];
    }

    /**
     * Trades what the two days prescribe while each keeps its own calendar
     * slot, and pins both so the next regeneration honours the choice.
     */
    private function swapSessions(PlannedSession $from, PlannedSession $to): void
    {
        $fromWorkout = $from->only(PlannedSession::WORKOUT_TRANSFER_FIELDS);
        $toWorkout = $to->only(PlannedSession::WORKOUT_TRANSFER_FIELDS);

        $to->update([...$fromWorkout, 'pinned' => true]);
        $from->update([...$toWorkout, 'pinned' => true]);
    }

    private function authorizeOwner(Request $request, PlannedSession $plannedSession): void
    {
        /** @var User $user */
        $user = $request->user();
        if ($plannedSession->user_id !== $user->id) {
            abort(403);
        }
    }
}
