<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ExperienceLevel;
use App\Enums\GoalType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

/**
 * The onboarding wizard's preferences and first-goal steps are both
 * optional: the race fields are nullable individually, but `required_with`
 * ties them together so a partial submission (e.g. a distance with no date)
 * is rejected instead of silently creating a malformed race. The training
 * preference fields are independently nullable — {@see withValidator()}
 * only enforces the structural pairing between `sessions_per_week` and
 * `run_days` (and `long_run_day` within it) when `run_days` is actually
 * submitted.
 */
class CompleteOnboardingRequest extends FormRequest
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
            'race_date' => ['nullable', 'date', 'after:today', 'before:'.now()->addYears(5)->toDateString(), 'required_with:distance_m,goal_time_sec'],
            'distance_m' => ['nullable', 'integer', 'between:1000,300000', 'required_with:race_date,goal_time_sec'],
            'goal_time_sec' => ['nullable', 'integer', 'between:300,259200', 'required_with:race_date,distance_m'],
            'name' => ['nullable', 'string', 'max:120'],
            'experience_level' => ['nullable', Rule::enum(ExperienceLevel::class)],
            'sessions_per_week' => ['nullable', 'integer', 'between:2,6'],
            'goal_type' => ['nullable', Rule::enum(GoalType::class)],
            'run_days' => ['nullable', 'array', 'min:2', 'max:6'],
            'run_days.*' => ['integer', 'between:0,6', 'distinct'],
            'long_run_day' => ['nullable', 'integer', 'between:0,6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'race_date.after' => 'Race day has to be in the future.',
            'race_date.before' => 'That\'s further out than we can plan for right now.',
            'race_date.required_with' => 'Add a race day, distance, and goal time together, or leave all three blank.',
            'distance_m.between' => 'Distance should be between 1 km and 300 km.',
            'distance_m.required_with' => 'Add a race day, distance, and goal time together, or leave all three blank.',
            'goal_time_sec.between' => 'Goal time should be between 5 minutes and 72 hours.',
            'goal_time_sec.required_with' => 'Add a race day, distance, and goal time together, or leave all three blank.',
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
