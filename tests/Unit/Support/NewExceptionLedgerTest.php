<?php

declare(strict_types=1);

use App\Support\NewExceptionLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    $this->freezeTime();
});

function serverException(string $message = 'boom'): RuntimeException
{
    return new RuntimeException($message);
}

it('records a never-seen server exception once with its class, file:line, first-seen time and count', function (): void {
    $exception = serverException();

    NewExceptionLedger::recordServer($exception);
    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    NewExceptionLedger::recordServer($exception);

    $pending = NewExceptionLedger::pull();

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['label'])->toBe('RuntimeException at tests/Unit/Support/NewExceptionLedgerTest.php:16')
        ->and($pending[0]['count'])->toBe(2)
        ->and($pending[0]['first_seen'])->toBe(Carbon::now()->subMinutes(5)->toIso8601String());
});

it('keys a server fingerprint on class and file:line, never on the message', function (): void {
    NewExceptionLedger::recordServer(serverException('athlete 1'));
    NewExceptionLedger::recordServer(serverException('athlete 2'));

    $pending = NewExceptionLedger::pull();

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['count'])->toBe(2)
        ->and(json_encode($pending))->not->toContain('athlete');
});

it('does not list a fingerprint again once a digest has taken it', function (): void {
    $exception = serverException();

    NewExceptionLedger::recordServer($exception);
    expect(NewExceptionLedger::pull())->toHaveCount(1);

    NewExceptionLedger::recordServer($exception);
    expect(NewExceptionLedger::pull())->toBe([]);
});

it('forgets a fingerprint after the seen window, so it can be new again', function (): void {
    config(['cache.default' => 'array']);
    $exception = serverException();

    NewExceptionLedger::recordServer($exception);
    NewExceptionLedger::pull();

    Carbon::setTestNow(Carbon::now()->addDays(NewExceptionLedger::SEEN_DAYS)->addMinute());
    NewExceptionLedger::recordServer($exception);

    expect(NewExceptionLedger::pull())->toHaveCount(1);
});

function thrownInVendor(): RuntimeException
{
    $exception = new RuntimeException('SQLSTATE');
    $file = new ReflectionProperty(Exception::class, 'file');
    $file->setValue($exception, base_path('vendor/laravel/framework/src/Illuminate/Database/Connection.php'));
    $line = new ReflectionProperty(Exception::class, 'line');
    $line->setValue($exception, 825);

    return $exception;
}

it('keys an exception thrown inside vendor on the first app frame that reached it', function (): void {
    $fromOneCaller = thrownInVendor();
    $fromAnotherCaller = thrownInVendor();

    NewExceptionLedger::recordServer($fromOneCaller);
    NewExceptionLedger::recordServer($fromAnotherCaller);

    $labels = array_column(NewExceptionLedger::pull(), 'label');

    expect($labels)->toHaveCount(2)
        ->and($labels[0])->toBe('RuntimeException at tests/Unit/Support/NewExceptionLedgerTest.php:'.$fromOneCaller->getTrace()[0]['line'])
        ->and(implode(' ', $labels))->not->toContain('vendor/');
});

it('does not wait on the ledger lock to count a fingerprint it has already seen', function (): void {
    Carbon::setTestNow();
    $exception = serverException();
    NewExceptionLedger::recordServer($exception);
    Cache::lock('ops.exceptions.lock', 10)->get();

    $startedAt = microtime(true);
    NewExceptionLedger::recordServer($exception);

    expect(microtime(true) - $startedAt)->toBeLessThan(1.0);
});

it('records a browser error by message and first frame, showing only the frame path', function (): void {
    $stack = "TypeError: athlete 42 is undefined\n    at Run (https://temari.example/build/assets/app-abc123.js:1:2345)\n    at x (https://temari.example/build/assets/app-abc123.js:1:99)";

    NewExceptionLedger::recordBrowser('TypeError: athlete 42 is undefined', $stack, guest: false);
    NewExceptionLedger::recordBrowser('TypeError: athlete 42 is undefined', $stack, guest: false);
    NewExceptionLedger::recordBrowser('TypeError: another message', $stack, guest: false);

    $pending = NewExceptionLedger::pull();

    expect($pending)->toHaveCount(2)
        ->and($pending[0]['label'])->toStartWith('browser error at /build/assets/app-abc123.js:1:2345')
        ->and($pending[0]['count'])->toBe(2)
        ->and($pending[0]['label'])->not->toBe($pending[1]['label'])
        ->and(json_encode($pending))->not->toContain('athlete')
        ->and(json_encode($pending))->not->toContain('temari.example');
});

it('builds a browser label only from a /build/assets/ frame, so caller text never reaches it', function (): void {
    NewExceptionLedger::recordBrowser(
        'visit evil.example for free shoes',
        "at https://evil.example/visit-evil-example:1:1\n    at https://temari.example/build/assets/Index-Dk9x1aB.js?v=2:3:44",
        guest: true,
    );

    expect(NewExceptionLedger::pull()[0]['label'])->toMatch('~^browser error at /build/assets/Index-Dk9x1aB\.js:3:44 \(#[0-9a-f]{8}\)$~');
});

it('uses a fixed label when no frame is under /build/assets/', function (string $stack): void {
    NewExceptionLedger::recordBrowser('visit evil.example', $stack, guest: true);

    expect(NewExceptionLedger::pull()[0]['label'])->toMatch('~^browser error at unknown frame \(#[0-9a-f]{8}\)$~');
})->with([
    'page url' => 'at https://temari.example/activities/987654?tab=splits:12:4',
    'caller path' => 'at /visit-evil-example-for-free-shoes:1:1',
    'asset outside build' => 'at https://evil.example/assets/free-shoes.js:1:1',
    'not a script' => 'at https://evil.example/build/assets/free shoes.css:1:1',
]);

it('labels a browser error with no stack by an unknown frame', function (): void {
    NewExceptionLedger::recordBrowser('Script error.', null, guest: false);

    expect(NewExceptionLedger::pull()[0]['label'])->toStartWith('browser error at unknown frame');
});

it('stops taking new fingerprints past the pending cap until a digest clears them', function (): void {
    for ($i = 0; $i < NewExceptionLedger::MAX_PENDING + 3; $i++) {
        NewExceptionLedger::recordBrowser("message {$i}", null, guest: false);
    }

    expect(NewExceptionLedger::pull())->toHaveCount(NewExceptionLedger::MAX_PENDING);

    NewExceptionLedger::recordBrowser('message '.(NewExceptionLedger::MAX_PENDING + 1), null, guest: false);

    expect(NewExceptionLedger::pull())->toHaveCount(1);
});

it('counts a repeat from a signed-in session or a guest in whichever queue took the fingerprint', function (bool $firstGuest): void {
    NewExceptionLedger::recordBrowser('TypeError: x is undefined', null, guest: $firstGuest);
    NewExceptionLedger::recordBrowser('TypeError: x is undefined', null, guest: ! $firstGuest);
    NewExceptionLedger::recordBrowser('TypeError: x is undefined', null, guest: ! $firstGuest);

    $pending = NewExceptionLedger::pull();

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['count'])->toBe(3);
})->with([
    'guest first' => true,
    'signed in first' => false,
]);

it('caps guest browser errors at their own smaller budget', function (): void {
    for ($i = 0; $i < NewExceptionLedger::MAX_PENDING + 3; $i++) {
        NewExceptionLedger::recordBrowser("guest {$i}", null, guest: true);
    }
    NewExceptionLedger::recordBrowser('signed in', null, guest: false);

    $labels = array_column(NewExceptionLedger::pull(), 'label');

    expect($labels)->toHaveCount(NewExceptionLedger::MAX_GUEST_PENDING + 1);
});

it('keeps room for server exceptions however full the browser queues are', function (): void {
    for ($i = 0; $i < NewExceptionLedger::MAX_PENDING + 3; $i++) {
        NewExceptionLedger::recordBrowser("guest {$i}", null, guest: true);
        NewExceptionLedger::recordBrowser("signed in {$i}", null, guest: false);
    }
    NewExceptionLedger::recordServer(serverException());

    $pending = NewExceptionLedger::pull();

    expect($pending)->toHaveCount(1 + NewExceptionLedger::MAX_PENDING + NewExceptionLedger::MAX_GUEST_PENDING)
        ->and($pending[0]['label'])->toStartWith('RuntimeException at ');
});

it('never throws when the cache is unavailable', function (): void {
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('redis down'));

    NewExceptionLedger::recordServer(serverException());
    NewExceptionLedger::recordBrowser('boom', null, guest: true);
})->throwsNoExceptions();
