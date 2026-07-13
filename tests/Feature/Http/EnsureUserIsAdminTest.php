<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Response;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function runAdminMiddleware(?User $user): string
{
    $request = Request::create('/ai-usage', 'GET');
    $request->setUserResolver(fn () => $user);

    return (new EnsureUserIsAdmin())->handle($request, fn (): Response => response('ok'))->getContent();
}

it('lets an admin through', function (): void {
    expect(runAdminMiddleware(User::factory()->admin()->make()))->toBe('ok');
});

it('aborts 403 for a non-admin', function (): void {
    expect(fn () => runAdminMiddleware(User::factory()->make()))
        ->toThrow(HttpException::class);
});

it('aborts 403 for a guest', function (): void {
    expect(fn () => runAdminMiddleware(null))
        ->toThrow(HttpException::class);
});
