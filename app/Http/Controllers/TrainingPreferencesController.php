<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanRegenerationReason;
use App\Http\Requests\UpdateTrainingPreferencesRequest;
use App\Models\User;
use App\Services\Run\Plan\PlanRegenerationService;
use Illuminate\Http\RedirectResponse;

class TrainingPreferencesController extends Controller
{
    public function update(
        UpdateTrainingPreferencesRequest $request,
        PlanRegenerationService $regeneration,
    ): RedirectResponse {
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

        // Run days, session count and long-run day decide the shape of every
        // week WeekPlanBuilder emits, so a saved preference that changed
        // nothing until Monday was a setting that appeared not to work.
        if (! $regeneration->regenerateForRequest($user, PlanRegenerationReason::Settings)) {
            return back()->with('info', 'Your training preferences are saved. The plan update is queued.');
        }

        return back()->with('success', 'Your training preferences are saved. Your plan\'s been reshaped around them.');
    }
}
