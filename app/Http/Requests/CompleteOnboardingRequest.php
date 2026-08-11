<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Override;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The onboarding wizard's first-goal step is optional: all three race
 * fields are nullable individually, but `required_with` ties them together
 * so a partial submission (e.g. a distance with no date) is rejected instead
 * of silently creating a malformed race.
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
}
