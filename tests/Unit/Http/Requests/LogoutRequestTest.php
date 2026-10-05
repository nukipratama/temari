<?php

declare(strict_types=1);

use App\Http\Requests\LogoutRequest;
use Illuminate\Support\Facades\Validator;

function passesLogout(array $data): bool
{
    return Validator::make($data, new LogoutRequest()->rules())->passes();
}

function resolvedLogoutRequest(array $data): LogoutRequest
{
    $request = LogoutRequest::create('/logout', 'POST', $data);
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    return $request;
}

it('authorizes the request', function (): void {
    expect(new LogoutRequest()->authorize())->toBeTrue();
});

it('accepts a logout with no endpoint', function (): void {
    expect(passesLogout([]))->toBeTrue()
        ->and(passesLogout(['push_endpoint' => null]))->toBeTrue();
});

it('accepts an endpoint', function (): void {
    expect(passesLogout(['push_endpoint' => 'https://fcm.googleapis.com/fcm/send/abc']))->toBeTrue();
});

it('rejects an endpoint over the length limit', function (): void {
    expect(passesLogout(['push_endpoint' => str_repeat('a', 501)]))->toBeFalse();
});

it('returns the endpoint, or null when absent or blank', function (): void {
    expect(resolvedLogoutRequest(['push_endpoint' => 'https://x.test/a'])->pushEndpoint())->toBe('https://x.test/a')
        ->and(resolvedLogoutRequest(['push_endpoint' => ''])->pushEndpoint())->toBeNull()
        ->and(resolvedLogoutRequest([])->pushEndpoint())->toBeNull();
});
