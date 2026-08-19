<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreRaceGoalRequest;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\TrainingLoad;
use App\Support\SharedPropCacheKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Race", not "Goal": the user-facing name for training-toward-a-race. The
 * DB layer still says `RaceGoal`/`race_goals` (implementation detail);
 * `GoalResolver` already means the unrelated accessory-unlock progress
 * catalog (surfaced on `/accessories`), so this stays namespaced apart from it.
 */
class RaceController extends Controller
{
    public function index(Request $request, RiegelProjector $projector, TrainingLoad $trainingLoad): Response
    {
        /** @var User $user */
        $user = $request->user();

        $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

        return Inertia::render('Race', [
            'race' => $this->racePayload($race),
            'projection' => $race === null ? null : $projector->project($user, (float) $race->distance_m),
            'ctlTrend' => $trainingLoad->ctlTrend($user),
        ]);
    }

    /**
     * Creating a race always sets it as the athlete's one active race — this
     * is also how the form's "edit" affordance works: submitting the form
     * again supersedes the current active race with the new values while
     * keeping the old one on record (`completed_at` stamped, never deleted).
     */
    public function store(StoreRaceGoalRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user, $request): void {
            RaceGoal::query()
                ->where('user_id', $user->id)
                ->active()
                ->update(['completed_at' => now()]);

            RaceGoal::query()->create([
                'user_id' => $user->id,
                'race_date' => $request->validated('race_date'),
                'distance_m' => $request->validated('distance_m'),
                'goal_time_sec' => $request->validated('goal_time_sec'),
                'name' => $request->validated('name'),
            ]);
        });

        // The model's own saved()/deleted() hooks already bust this per row,
        // but they fire mid-transaction (before commit) here — busted again
        // after the commit so a concurrent read can't re-warm the cache from
        // the pre-swap state. Same reasoning as AccessoryController::equip().
        SharedPropCacheKey::ActiveRace->forget($user->id);

        return back()->with('success', 'Your race is set. Temari will keep the plan honest against it.');
    }

    /**
     * @return array{id: int, race_date: string, distance_m: int, goal_time_sec: int, name: string|null}|null
     */
    private function racePayload(?RaceGoal $race): ?array
    {
        if ($race === null) {
            return null;
        }

        return [
            'id' => $race->id,
            'race_date' => $race->race_date->toDateString(),
            'distance_m' => $race->distance_m,
            'goal_time_sec' => $race->goal_time_sec,
            'name' => $race->name,
        ];
    }
}
