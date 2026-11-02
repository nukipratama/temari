<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Notifications\QuietHours;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Every field must be sent (`present` for the nullable send time) because the
 * client always sends the complete state: a partial write would silently leave
 * `updateOrCreate` holding whatever was there before, which reads as a toggle
 * that did not stick.
 *
 * A send time ends before quiet hours begin: one held past midnight would
 * deliver the previous day's briefing.
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
            'briefing_push_enabled' => ['required', 'boolean'],
            'briefing_push_minute' => ['present', 'nullable', 'integer', 'between:0,'.(QuietHours::STARTS_AT_HOUR * 60 - 1)],
            'telegram_enabled' => ['required', 'boolean'],
            'push_enabled' => ['required', 'boolean'],
        ];
    }
}
