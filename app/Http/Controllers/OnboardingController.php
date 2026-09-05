<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CompleteOnboardingRequest;
use App\Models\RaceGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->onboarded_at !== null) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Onboarding/Index');
    }

    public function store(CompleteOnboardingRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->filled('race_date')) {
            // Locked check-then-create: a retried/double submit racing itself
            // must not create a second active race (RaceGoal allows only one).
            DB::transaction(function () use ($user, $request): void {
                $hasActiveRace = RaceGoal::query()
                    ->where('user_id', $user->id)
                    ->active()
                    ->lockForUpdate()
                    ->exists();

                if (! $hasActiveRace) {
                    RaceGoal::query()->create([
                        'user_id' => $user->id,
                        'race_date' => $request->validated('race_date'),
                        'distance_m' => $request->validated('distance_m'),
                        'goal_time_sec' => $request->validated('goal_time_sec'),
                        'name' => $request->validated('name'),
                    ]);
                }
            });
        }

        if ($request->hasAny(['experience_level', 'sessions_per_week', 'goal_type', 'run_days', 'long_run_day'])) {
            TrainingPreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'experience_level' => $request->validated('experience_level'),
                    'sessions_per_week' => $request->validated('sessions_per_week'),
                    'goal_type' => $request->validated('goal_type'),
                    'run_days' => $request->validated('run_days'),
                    'long_run_day' => $request->validated('long_run_day'),
                ],
            );
        }

        $user->markOnboarded();

        return redirect()->route('dashboard')->with('success', 'You\'re all set. Let\'s see how you\'ve been running.');
    }
}
