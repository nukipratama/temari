<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ExperienceLevel;
use App\Enums\GoalType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a Settings-page training-preferences edit. Every field is
 * independently nullable — clearing a field back to null hands control back
 * to {@see \App\Services\Run\Plan\TrainingBaseline}'s own fallback for it.
 * {@see withValidator()} mirrors {@see CompleteOnboardingRequest}'s
 * structural pairing between `sessions_per_week`, `run_days` and
 * `long_run_day`.
 */
class UpdateTrainingPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'experience_level' => ['nullable', Rule::enum(ExperienceLevel::class)],
            'sessions_per_week' => ['nullable', 'integer', 'between:2,6'],
            'goal_type' => ['nullable', Rule::enum(GoalType::class)],
            'run_days' => ['nullable', 'array', 'min:2', 'max:6'],
            'run_days.*' => ['integer', 'between:0,6', 'distinct'],
            'long_run_day' => ['nullable', 'integer', 'between:0,6'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $runDays = $data['run_days'] ?? null;

            if ($runDays === null) {
                return;
            }

            $sessionsPerWeek = $data['sessions_per_week'] ?? null;
            if ($sessionsPerWeek !== null && count($runDays) !== (int) $sessionsPerWeek) {
                $validator->errors()->add('run_days', 'Pick as many days as your sessions-per-week target.');
            }

            $longRunDay = $data['long_run_day'] ?? null;
            if ($longRunDay !== null && ! in_array((int) $longRunDay, array_map(intval(...), $runDays), true)) {
                $validator->errors()->add('long_run_day', 'Your long run day has to be one of your chosen run days.');
            }
        });
    }
}
