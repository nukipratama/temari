<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Override;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a race-goal submission. Bounds are deliberately generous (this is
 * the first genuinely user-authored write path in the app) but real: a race
 * must be in the future, and distance/time stay within what a real road or
 * ultra race looks like, to keep the Riegel projection meaningful.
 */
class StoreRaceGoalRequest extends FormRequest
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
            'race_date' => ['required', 'date', 'after:today', 'before:'.now()->addYears(5)->toDateString()],
            // 1 km to 300 km covers everything from a short race up to a
            // multi-day ultra without accepting a typo'd distance.
            'distance_m' => ['required', 'integer', 'between:1000,300000'],
            // 5 minutes to 72 hours, same "plausible ultra" ceiling.
            'goal_time_sec' => ['required', 'integer', 'between:300,259200'],
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
            'distance_m.between' => 'Distance should be between 1 km and 300 km.',
            'goal_time_sec.between' => 'Goal time should be between 5 minutes and 72 hours.',
        ];
    }
}
