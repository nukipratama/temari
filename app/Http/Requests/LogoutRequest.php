<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LogoutRequest extends FormRequest
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
            'push_endpoint' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function pushEndpoint(): ?string
    {
        $endpoint = $this->validated('push_endpoint');

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }
}
