<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanRegenerationReason;
use App\Http\Requests\StoreRaceGoalRequest;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Plan\PlanRegenerationService;
use App\Services\Run\Plan\RaceGoalService;
use App\Services\Run\Plan\RacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Race", not "Goal": the user-facing name for training-toward-a-race. The
 * DB layer still says `RaceGoal`/`race_goals` (implementation detail).
 */
class RaceController extends Controller
{
    public function index(Request $request, RiegelProjector $projector, RacePresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

        return Inertia::render('Race', [
            'race' => $race === null ? null : $presenter->present($user, $race),
            'projection' => $race === null ? null : $projector->project($user, (float) $race->distance_m),
        ]);
    }

    /**
     * With a race already active the request carries an explicit intent:
     * `update` (the default) revises that event and keeps its season, `new`
     * starts a new event and so a new season. See {@see RaceGoalService}.
     */
    public function store(
        StoreRaceGoalRequest $request,
        RaceGoalService $races,
        PlanRegenerationService $regeneration,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $race = $races->submit($user, $request->raceAttributes(), $request->intent());

        if (! $race->wasRecentlyCreated && ! $race->wasChanged(['race_date', 'goal_time_sec'])) {
            return back()->with('success', 'Your race is saved.');
        }

        // A date or target change reshapes the arc, so the plan is rebuilt now
        // rather than on Monday, and only when one of them actually moved.
        if (! $regeneration->regenerateForRequest($user, PlanRegenerationReason::Settings)) {
            return back()->with('info', 'Your race is saved. The plan update is queued.');
        }

        return back()->with('success', 'Your race is set. Temari will keep the plan honest against it.');
    }

    /**
     * Calls the race off and returns the plan to its self-scaled arc. The
     * event stays on record as cancelled, never deleted.
     */
    public function destroy(
        Request $request,
        RaceGoalService $races,
        PlanRegenerationService $regeneration,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        if ($races->cancel($user) === null) {
            return back();
        }

        if (! $regeneration->regenerateForRequest($user, PlanRegenerationReason::Settings)) {
            return back()->with('info', 'Race cleared. The plan update is queued.');
        }

        return back()->with('success', 'Race cleared. Temari\'s back to building around your own running.');
    }
}
