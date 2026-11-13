<?php

declare(strict_types=1);

use App\Http\Requests\UpdateNotificationPreferenceRequest;
use Illuminate\Support\Facades\Validator;

/**
 * @param  array<string, mixed>  $data
 */
function passesNotificationPreference(array $data): bool
{
    return Validator::make($data, new UpdateNotificationPreferenceRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new UpdateNotificationPreferenceRequest()->authorize())->toBeTrue();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function notificationPreferencePayload(array $overrides = []): array
{
    return [
        'notifications_enabled' => true,
        'telegram_enabled' => false,
        'push_enabled' => true,
        'briefing_push_enabled' => true,
        'briefing_push_minute' => null,
        ...$overrides,
    ];
}

it('accepts the complete state', function (?int $minute): void {
    expect(passesNotificationPreference(notificationPreferencePayload(['briefing_push_minute' => $minute])))->toBeTrue();
})->with([
    'an automatic send time' => null,
    'midnight' => 0,
    'the last quarter hour before quiet hours' => 21 * 60 + 45,
]);

it('rejects a non-boolean flag', function (string $flag): void {
    expect(passesNotificationPreference(notificationPreferencePayload([$flag => 'maybe'])))->toBeFalse();
})->with(['notifications_enabled', 'briefing_push_enabled']);

it('rejects a send time outside the day, after quiet hours begin, or off the quarter hour', function (mixed $minute): void {
    expect(passesNotificationPreference(notificationPreferencePayload(['briefing_push_minute' => $minute])))->toBeFalse();
})->with([
    'before midnight' => -1,
    'inside the evening quiet hours, which would hold it into tomorrow' => 22 * 60,
    'a full day' => 24 * 60,
    'a clock string' => '06:30',
    'off the quarter hour' => 5 * 60 + 10,
]);

it('rejects a partial payload missing a flag', function (array $payload): void {
    expect(passesNotificationPreference($payload))->toBeFalse();
})->with([
    'the channel mutes' => [['notifications_enabled' => true]],
    'the master switch' => [['telegram_enabled' => true, 'push_enabled' => true]],
    'the briefing switch' => [array_diff_key(notificationPreferencePayload(), ['briefing_push_enabled' => true])],
    'the briefing send time' => [array_diff_key(notificationPreferencePayload(), ['briefing_push_minute' => null])],
]);
