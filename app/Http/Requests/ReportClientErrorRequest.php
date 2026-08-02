<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportClientErrorRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'string', 'max:2000'],
            'componentStack' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
