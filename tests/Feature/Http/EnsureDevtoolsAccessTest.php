<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDevtoolsAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

function runDevtoolsMiddleware(?string $password): Response
{
    $request = Request::create('/ai-usage', 'GET');
    if ($password !== null) {
        $request->headers->set('PHP_AUTH_PW', $password);
    }

    return new EnsureDevtoolsAccess()->handle($request, fn (): Response => response('ok'));
}

beforeEach(function (): void {
    config(['devtools.password' => 'secret']);
});

it('lets a request through with the correct password', function (): void {
    expect(runDevtoolsMiddleware('secret')->getContent())->toBe('ok');
});

it('challenges a request with the wrong password', function (): void {
    $response = runDevtoolsMiddleware('wrong');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Basic realm="Devtools"');
});

it('challenges a request with no password at all', function (): void {
    expect(runDevtoolsMiddleware(null)->getStatusCode())->toBe(401);
});

it('challenges every request when devtools.password is unconfigured', function (): void {
    config(['devtools.password' => null]);

    expect(runDevtoolsMiddleware(null)->getStatusCode())->toBe(401)
        ->and(runDevtoolsMiddleware('')->getStatusCode())->toBe(401);
});
