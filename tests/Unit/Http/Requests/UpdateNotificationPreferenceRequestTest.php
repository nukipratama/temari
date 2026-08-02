<?php

declare(strict_types=1);

use App\Http\Requests\UpdateNotificationPreferenceRequest;
use Illuminate\Support\Facades\Validator;

function passesNotificationPreference(array $data): bool
{
    return Validator::make($data, new UpdateNotificationPreferenceRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new UpdateNotificationPreferenceRequest()->authorize())->toBeTrue();
});

it('accepts the complete boolean state', function (): void {
    expect(passesNotificationPreference([
        'notifications_enabled' => true,
        'telegram_enabled' => false,
        'push_enabled' => true,
    ]))->toBeTrue();
});

it('rejects a non-boolean flag', function (): void {
    expect(passesNotificationPreference([
        'notifications_enabled' => 'maybe',
        'telegram_enabled' => true,
        'push_enabled' => true,
    ]))->toBeFalse();
});

it('rejects a partial payload missing a flag', function (): void {
    expect(passesNotificationPreference(['notifications_enabled' => true]))->toBeFalse();
});
