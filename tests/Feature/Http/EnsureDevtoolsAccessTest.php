<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDevtoolsAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

function runDevtoolsMiddleware(?string $password): Response
{
    $request = Request::create('/devtools/narration', 'GET');
    if ($password !== null) {
        $request->headers->set('PHP_AUTH_PW', $password);
    }

    return new EnsureDevtoolsAccess()->handle($request, fn (): Response => response('ok'));
}

beforeEach(function (): void {
    config(['devtools.password' => 'secret']);
    app()->detectEnvironment(fn (): string => 'production');
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

it('skips the challenge entirely in the local environment', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config(['devtools.password' => null]);

    expect(runDevtoolsMiddleware(null)->getContent())->toBe('ok');
});

it('fails closed with an empty password in every non-local environment', function (string $environment, mixed $password): void {
    app()->detectEnvironment(fn (): string => $environment);
    config(['devtools.password' => $password]);

    expect(runDevtoolsMiddleware(null)->getStatusCode())->toBe(401)
        ->and(runDevtoolsMiddleware('')->getStatusCode())->toBe(401)
        ->and(runDevtoolsMiddleware('anything')->getStatusCode())->toBe(401);
})->with(['production', 'staging', 'testing'])->with([null, '']);

it('challenges outside production as well when a password is configured', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    expect(runDevtoolsMiddleware('wrong')->getStatusCode())->toBe(401)
        ->and(runDevtoolsMiddleware('secret')->getContent())->toBe('ok');
})->with(['staging', 'testing']);

it('locks an address out after five wrong passwords, even for the right one', function (): void {
    foreach (range(1, 5) as $attempt) {
        expect(runDevtoolsMiddleware("wrong-{$attempt}")->getStatusCode())->toBe(401);
    }

    $locked = runDevtoolsMiddleware('secret');

    expect($locked->getStatusCode())->toBe(429)
        ->and($locked->headers->get('Retry-After'))->not->toBeNull();
});

it('does not count a request with no password against the lockout', function (): void {
    foreach (range(1, 8) as $attempt) {
        expect(runDevtoolsMiddleware(null)->getStatusCode())->toBe(401);
    }

    expect(runDevtoolsMiddleware('secret')->getContent())->toBe('ok');
});

it('keeps the lockout per address', function (): void {
    foreach (range(1, 5) as $attempt) {
        runDevtoolsMiddleware("wrong-{$attempt}");
    }

    $request = Request::create('/devtools/narration', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
    $request->headers->set('PHP_AUTH_PW', 'secret');

    $response = new EnsureDevtoolsAccess()->handle($request, fn (): Response => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('ships no devtools password in the example env, so a copied env fails closed in production', function (): void {
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^DEVTOOLS_PASSWORD=$/m');
});
