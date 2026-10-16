<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Override;

class StoreIntervalsIcuConnectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'athlete_id' => ['required', 'string', 'regex:/^i\d{1,12}$/'],
            'api_key' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'athlete_id.regex' => 'that doesn\'t look like an intervals.icu athlete ID, which is an i followed by digits.',
        ];
    }

    /**
     * Accepts the ID with or without its leading `i`, as intervals.icu shows both.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $athleteId = strtolower(trim((string) $this->input('athlete_id')));

        $this->merge([
            'athlete_id' => preg_match('/^\d+$/', $athleteId) === 1 ? 'i'.$athleteId : $athleteId,
            'api_key' => trim((string) $this->input('api_key')),
        ]);
    }
}
