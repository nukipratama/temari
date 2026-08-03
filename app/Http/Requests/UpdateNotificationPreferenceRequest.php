<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Every field is `required` because the client always sends the complete state:
 * a partial write would silently leave `updateOrCreate` holding whatever was
 * there before, which reads as a toggle that did not stick.
 */
class UpdateNotificationPreferenceRequest extends FormRequest
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
            'notifications_enabled' => ['required', 'boolean'],
            'telegram_enabled' => ['required', 'boolean'],
            'push_enabled' => ['required', 'boolean'],
        ];
    }
}
