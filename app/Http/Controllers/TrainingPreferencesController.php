<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTrainingPreferencesRequest;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

class TrainingPreferencesController extends Controller
{
    public function update(
        UpdateTrainingPreferencesRequest $request,
        Periodizer $periodizer,
        PlanNarrationRequester $narrationRequester,
    ): RedirectResponse {
        app(NarrationOrigin::class)->set(AnalysisOrigin::User);

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
        $periodizer->regenerate($user);

        if ($user->is_demo) {
            $narrationRequester->ensureDemoFilled($user, Carbon::today());
        } else {
            $narrationRequester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today());
        }

        return back()->with('success', 'Your training preferences are saved. Your plan\'s been reshaped around them.');
    }
}
