<?php

declare(strict_types=1);

use App\Http\Requests\DestroyPushSubscriptionRequest;
use Illuminate\Support\Facades\Validator;

function passesDestroyPushSubscription(array $data): bool
{
    return Validator::make($data, new DestroyPushSubscriptionRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new DestroyPushSubscriptionRequest()->authorize())->toBeTrue();
});

it('accepts a valid endpoint', function (): void {
    expect(passesDestroyPushSubscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc']))->toBeTrue();
});

it('rejects a missing endpoint', function (): void {
    expect(passesDestroyPushSubscription([]))->toBeFalse();
});

it('rejects an endpoint over the length limit', function (): void {
    expect(passesDestroyPushSubscription(['endpoint' => str_repeat('a', 501)]))->toBeFalse();
});
