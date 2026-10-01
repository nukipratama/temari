<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RaceOutcome;
use App\Models\RaceGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRaceOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && RaceGoal::query()->where('user_id', $user->id)->whereKey($this->route('race'))->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(RaceOutcome::class)],
            'activity_id' => ['nullable', 'integer'],
            'finish_time_sec' => ['nullable', 'integer', 'between:300,259200'],
        ];
    }
}
