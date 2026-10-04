<?php

declare(strict_types=1);

use App\Http\Requests\SeenPushSubscriptionRequest;
use Illuminate\Support\Facades\Validator;

function passesSeenPushSubscription(array $data): bool
{
    return Validator::make($data, new SeenPushSubscriptionRequest()->rules())->passes();
}

it('authorizes the request', function (): void {
    expect(new SeenPushSubscriptionRequest()->authorize())->toBeTrue();
});

it('accepts a valid endpoint', function (): void {
    expect(passesSeenPushSubscription(['endpoint' => 'https://web.push.apple.com/abc']))->toBeTrue();
});

it('rejects a missing endpoint', function (): void {
    expect(passesSeenPushSubscription([]))->toBeFalse();
});

it('rejects an endpoint over the length limit', function (): void {
    expect(passesSeenPushSubscription(['endpoint' => str_repeat('a', 501)]))->toBeFalse();
});
