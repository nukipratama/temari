<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTrainingPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class TrainingPreferencesController extends Controller
{
    public function update(UpdateTrainingPreferencesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->trainingPreference()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'experience_level' => $request->validated('experience_level'),
                'sessions_per_week' => $request->validated('sessions_per_week'),
                'goal_type' => $request->validated('goal_type'),
                'run_days' => $request->validated('run_days'),
                'long_run_day' => $request->validated('long_run_day'),
            ],
        );

        return back()->with('success', 'Your training preferences are saved. They\'ll shape your next plan regenerate.');
    }
}
