<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PerformanceEvidenceKind;
use App\Models\User;
use App\Services\Run\Plan\PerformanceEvidenceRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PerformanceEvidenceController extends Controller
{
    public function __construct(private readonly PerformanceEvidenceRecorder $recorder)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validate([
            'kind' => ['required', Rule::enum(PerformanceEvidenceKind::class)],
            'distance_m' => ['required', 'integer', 'between:'.PerformanceEvidenceRecorder::MIN_DISTANCE_M.','.PerformanceEvidenceRecorder::MAX_DISTANCE_M],
            'elapsed_time_sec' => ['required', 'integer', 'between:60,604800'],
            'performed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'activity_id' => ['nullable', 'integer', Rule::exists('activities', 'id')->where('user_id', $user->id)],
            'race_goal_id' => ['nullable', 'integer', Rule::exists('race_goals', 'id')->where('user_id', $user->id)],
        ]);

        $recorded = $this->recorder->record($user, $attributes);

        return response()->json([
            'id' => $recorded['evidence']->id,
            'fitness' => $recorded['fitness'],
            'plan_updated' => $recorded['plan_updated'],
        ], $recorded['evidence']->wasRecentlyCreated ? 201 : 200);
    }
}
