<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RaceOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRaceOutcomeRequest extends FormRequest
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
            'outcome' => ['required', Rule::enum(RaceOutcome::class)],
            'activity_id' => ['nullable', 'integer'],
            'finish_time_sec' => ['nullable', 'integer', 'between:300,259200'],
        ];
    }
}
