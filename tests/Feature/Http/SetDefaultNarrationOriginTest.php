<?php

declare(strict_types=1);

use App\Http\Middleware\SetDefaultNarrationOrigin;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

function runSetDefaultNarrationOriginMiddleware(?User $user): Response
{
    $request = Request::create('/', 'GET');
    if ($user !== null) {
        $request->setUserResolver(fn (): User => $user);
    }

    return new SetDefaultNarrationOrigin()->handle($request, fn (): Response => response('ok'));
}

it('stamps the request-level default to User for an authenticated request', function (): void {
    $user = User::factory()->create();

    runSetDefaultNarrationOriginMiddleware($user);

    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::User);
});

it('leaves the origin untouched for a guest request', function (): void {
    runSetDefaultNarrationOriginMiddleware(null);

    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::Unknown);
});

it('passes the request through to the next handler', function (): void {
    $user = User::factory()->create();

    expect(runSetDefaultNarrationOriginMiddleware($user)->getContent())->toBe('ok');
});
