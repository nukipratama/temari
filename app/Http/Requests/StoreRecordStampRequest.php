<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Run\Metrics\PrBibResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the `record_key` on the PR-bib-stamp claim endpoint against the
 * closed set {@see PrBibResolver} tracks. Whether the authenticated user
 * actually holds that record is checked in the controller, since it needs a
 * DB lookup keyed by the resolved user.
 */
class StoreRecordStampRequest extends FormRequest
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
            'record_key' => [
                'required',
                'string',
                Rule::in([...array_keys(PrBibResolver::TRACKED_CATEGORIES), PrBibResolver::LONGEST_RUN_KEY]),
            ],
        ];
    }

    public function recordKey(): string
    {
        return (string) $this->validated('record_key');
    }
}
