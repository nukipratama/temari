<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\AI\AnalysisOrigin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowTokenUsageRequest extends FormRequest
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
            'range' => ['sometimes', 'in:today,7d,30d,month,all,custom'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'kind' => ['sometimes', 'string'],
            // Closed set: an unknown origin would silently return an empty report
            // rather than the unfiltered one the operator expected.
            'origin' => ['sometimes', Rule::enum(AnalysisOrigin::class)],
        ];
    }
}
