<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanRegenerationReason;
use App\Enums\SessionType;
use App\Http\Requests\UpdatePlannedSessionRequest;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanRegenerationService;
use App\Services\Run\Plan\PlanPageAssembler;
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
            'disclaimerHeadline' => TrainingDisclaimer::HEADLINE,
            'planNarration' => Inertia::defer(fn (): array => $plan->planNarration($user, $today)),
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
     * Move (date), block (session_type = rest), skip (excuse the day before
     * it passes), or pin/unpin. Any explicit edit fixes the day (pins it) so
     * the next regeneration doesn't silently overwrite it, unless the caller
     * passes `pinned: false` to hand control back to the periodizer.
     *
     * A move onto a date the athlete already has a row for is a **swap**, not
     * a re-date: `planned_sessions` is unique on (user_id, date) and the
     * periodizer materializes all seven days of every week, so every in-horizon
     * target is occupied.
     */
    public function update(
        UpdatePlannedSessionRequest $request,
        PlannedSession $plannedSession,
        Periodizer $periodizer,
        PlanNarrationRequester $narrationRequester,
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
            [$session, $occupant, $touchedSessions] = $periodizer->withRegenerationLock(
                $user,
                function () use ($user, $plannedSession, $attributes, $today): array {
                    return DB::transaction(function () use ($user, $plannedSession, $attributes, $today): array {
                        $session = PlannedSession::query()
                            ->where('user_id', $user->id)
                            ->whereDate('date', $plannedSession->date->toDateString())
                            ->lockForUpdate()
                            ->first();

                        if ($session === null || $session->id !== $plannedSession->id) {
                            abort(409, 'This plan changed while you were editing. Reload and try again.');
                        }

                        if (isset($attributes['date']) && ! $session->date->isAfter($today)) {
                            throw ValidationException::withMessages(['date' => 'only a day still ahead can be moved.']);
                        }

                        $occupant = $this->occupantOfMoveTarget($session, $attributes['date'] ?? null);
                        if ($occupant !== null && $occupant->session_type !== SessionType::Rest) {
                            throw ValidationException::withMessages(['date' => 'a session can only move onto a rest day.']);
                        }

                        $touchedSessions = [$session];
                        if ($occupant !== null) {
                            $touchedSessions[] = $occupant;
                            $this->swapSessions($session, $occupant);
                            unset($attributes['date']);
                        }

                        $session->update($attributes);

                        return [$session, $occupant, $touchedSessions];
                    });
                },
                Periodizer::REQUEST_LOCK_WAIT_SECONDS,
            );
        } catch (LockTimeoutException) {
            return back()->with('info', 'The plan is updating right now. Reload and try your edit again.');
        }

        if ($occupant !== null || $session->wasChanged(['session_type', 'skipped', 'date'])) {
            foreach ($touchedSessions as $touchedSession) {
                if ($touchedSession->status->isCredited() && $narrationRequester->isWithinCurrentWeek($touchedSession->date, $today)) {
                    $narrationRequester->requestDayNarration($touchedSession->user_id, $touchedSession->date);
                }
            }
        }

        return back();
    }

    private function occupantOfMoveTarget(PlannedSession $plannedSession, ?string $toDate): ?PlannedSession
    {
        if ($toDate === null || Carbon::parse($toDate)->isSameDay($plannedSession->date)) {
            return null;
        }

        return PlannedSession::query()
            ->where('user_id', $plannedSession->user_id)
            ->whereDate('date', $toDate)
            ->lockForUpdate()
            ->first();
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
