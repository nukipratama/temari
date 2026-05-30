<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\AI\AnalysisType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validates the loose inputs on the analysis-trigger endpoint: the `{type}`
 * route segment (must be a known {@see AnalysisType} backing value) and the
 * optional `discriminator` query string (a short opaque key). `{subjectId}` is
 * already constrained to digits by the route's `whereNumber`, so it is bounded
 * here as a positive integer for defence in depth.
 */
class TriggerAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Per-subject ownership is enforced in the controller against the
        // resolved AnalysisType, which needs the validated type first.
        return true;
    }

    /**
     * Fold the route segments into the validation payload so `type` and
     * `subjectId` are validated alongside the `discriminator` query input.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [
            ...$this->query(),
            'type' => $this->route('type'),
            'subjectId' => $this->route('subjectId'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(AnalysisType::class)],
            'subjectId' => ['required', 'integer', 'min:1'],
            'discriminator' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function discriminator(): ?string
    {
        $value = (string) $this->validated('discriminator', '');

        return $value === '' ? null : $value;
    }

    /**
     * Preserve the endpoint's established error contract: an unknown analysis
     * type returns `{"error": "unknown_analysis_type"}` (422) exactly as the
     * controller did before validation was extracted, while any other invalid
     * input falls back to the framework's default 422 envelope.
     */
    protected function failedValidation(Validator $validator): void
    {
        if (array_key_exists('type', $validator->errors()->toArray())) {
            throw new HttpResponseException(
                response()->json(['error' => 'unknown_analysis_type'], 422),
            );
        }

        parent::failedValidation($validator);
    }
}
