<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RecoveryConcernLevel;
use App\Enums\SleepQuality;
use App\Models\RecoveryFeedback;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class RecoveryFeedbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sleep_quality' => ['sometimes', 'nullable', Rule::enum(SleepQuality::class)],
            'fatigue' => ['sometimes', 'nullable', Rule::enum(RecoveryConcernLevel::class)],
            'soreness' => ['sometimes', 'nullable', Rule::enum(RecoveryConcernLevel::class)],
            'concerning_pain' => ['sometimes', 'nullable', 'boolean'],
            'illness' => ['sometimes', 'nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $feedback = RecoveryFeedback::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => $validated['date'] ?? today()->toDateString(),
            ],
            Arr::except($validated, ['date']),
        );

        return response()->json([
            'date' => $feedback->date->toDateString(),
            'sleep_quality' => $feedback->sleep_quality?->value,
            'fatigue' => $feedback->fatigue?->value,
            'soreness' => $feedback->soreness?->value,
            'concerning_pain' => $feedback->concerning_pain,
            'illness' => $feedback->illness,
            'updated_at' => $feedback->updated_at?->toISOString(),
        ], $feedback->wasRecentlyCreated ? 201 : 200);
    }
}
