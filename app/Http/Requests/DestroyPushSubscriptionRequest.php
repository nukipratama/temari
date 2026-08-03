<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestroyPushSubscriptionRequest extends FormRequest
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
            'endpoint' => ['required', 'string', 'max:500'],
        ];
    }

    public function endpoint(): string
    {
        return (string) $this->validated('endpoint');
    }
}
