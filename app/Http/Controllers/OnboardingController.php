<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CompleteOnboardingRequest;
use App\Models\RaceGoal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            RaceGoal::query()->create([
                'user_id' => $user->id,
                'race_date' => $request->validated('race_date'),
                'distance_m' => $request->validated('distance_m'),
                'goal_time_sec' => $request->validated('goal_time_sec'),
                'name' => $request->validated('name'),
            ]);
        }

        $user->markOnboarded();

        return redirect()->route('dashboard')->with('success', 'You\'re all set. Let\'s see how you\'ve been running.');
    }
}
