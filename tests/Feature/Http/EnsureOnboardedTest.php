<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureOnboarded;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

function runOnboardedMiddleware(?User $user): Response
{
    $request = Request::create('/', 'GET');
    if ($user !== null) {
        $request->setUserResolver(fn (): User => $user);
    }

    return new EnsureOnboarded()->handle($request, fn (): Response => response('ok'));
}

it('lets a guest request through untouched', function (): void {
    expect(runOnboardedMiddleware(null)->getContent())->toBe('ok');
});

it('lets an onboarded user through', function (): void {
    $user = User::factory()->create();

    expect(runOnboardedMiddleware($user)->getContent())->toBe('ok');
});

it('redirects an unboarded user to the onboarding wizard', function (): void {
    $user = User::factory()->needsOnboarding()->create();

    $response = runOnboardedMiddleware($user);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('onboarding.show'));
});
