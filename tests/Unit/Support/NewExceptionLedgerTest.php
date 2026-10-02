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
    $exception = serverException();

    NewExceptionLedger::recordServer($exception);
    NewExceptionLedger::pull();

    Carbon::setTestNow(Carbon::now()->addDays(NewExceptionLedger::SEEN_DAYS)->addMinute());
    NewExceptionLedger::recordServer($exception);

    expect(NewExceptionLedger::pull())->toHaveCount(1);
});

it('records a browser error by message and first frame, showing only the frame path', function (): void {
    $stack = "TypeError: athlete 42 is undefined\n    at Run (https://temari.example/build/assets/app-abc123.js:1:2345)\n    at x (https://temari.example/build/assets/app-abc123.js:1:99)";

    NewExceptionLedger::recordBrowser('TypeError: athlete 42 is undefined', $stack);
    NewExceptionLedger::recordBrowser('TypeError: athlete 42 is undefined', $stack);
    NewExceptionLedger::recordBrowser('TypeError: another message', $stack);

    $pending = NewExceptionLedger::pull();

    expect($pending)->toHaveCount(2)
        ->and($pending[0]['label'])->toStartWith('browser error at /build/assets/app-abc123.js:1:2345')
        ->and($pending[0]['count'])->toBe(2)
        ->and($pending[0]['label'])->not->toBe($pending[1]['label'])
        ->and(json_encode($pending))->not->toContain('athlete')
        ->and(json_encode($pending))->not->toContain('temari.example');
});

it('masks ids and drops the query string when the first frame is a page url', function (): void {
    NewExceptionLedger::recordBrowser('boom', 'at https://temari.example/activities/987654?tab=splits:12:4');

    expect(NewExceptionLedger::pull()[0]['label'])->toStartWith('browser error at /activities/{id}:12:4 (#');
});

it('labels a browser error with no stack by an unknown frame', function (): void {
    NewExceptionLedger::recordBrowser('Script error.', null);

    expect(NewExceptionLedger::pull()[0]['label'])->toStartWith('browser error at unknown frame');
});

it('stops taking new fingerprints past the pending cap until a digest clears them', function (): void {
    for ($i = 0; $i < NewExceptionLedger::MAX_PENDING + 3; $i++) {
        NewExceptionLedger::recordBrowser("message {$i}", null);
    }

    expect(NewExceptionLedger::pull())->toHaveCount(NewExceptionLedger::MAX_PENDING);

    NewExceptionLedger::recordBrowser('message '.(NewExceptionLedger::MAX_PENDING + 1), null);

    expect(NewExceptionLedger::pull())->toHaveCount(1);
});

it('never throws when the cache is unavailable', function (): void {
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('redis down'));

    NewExceptionLedger::recordServer(serverException());
    NewExceptionLedger::recordBrowser('boom', null);
})->throwsNoExceptions();
