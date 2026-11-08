<?php

declare(strict_types=1);

use App\Support\NewExceptionLedger;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

function spyOnClientErrorLog(): MockInterface
{
    $log = Log::spy();
    $log->shouldReceive('channel')->with('client-errors')->andReturnSelf();

    return $log;
}

it('logs a reported client error and returns no content', function (): void {
    $log = spyOnClientErrorLog();

    $this->postJson('/client-errors', [
        'message' => 'Boom',
        'url' => 'https://temari.test/activities',
        'stack' => 'at foo (app.tsx:1)',
        'componentStack' => 'in RunPage',
    ])->assertNoContent();

    $log->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'client-error'
            && $context['message'] === 'Boom'
            && $context['url'] === 'https://temari.test/activities',
    );
});

it('is reachable without authentication (captures guest-page errors)', function (): void {
    $this->postJson('/client-errors', ['message' => 'guest boom'])->assertNoContent();
});

it('writes only the leading frames of an oversized trace', function (): void {
    $log = spyOnClientErrorLog();

    $this->postJson('/client-errors', [
        'message' => 'Boom',
        'stack' => str_repeat('a', 5000),
        'componentStack' => str_repeat('b', 5000),
    ])->assertNoContent();

    $log->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_starts_with((string) $context['stack'], str_repeat('a', 2000))
            && mb_strlen((string) $context['stack']) < 2100
            && mb_strlen((string) $context['component_stack']) < 2100,
    );
});

it('strips control characters and newlines from every reported field before logging', function (): void {
    $log = spyOnClientErrorLog();

    $this->withHeaders(['Referer' => "https://temari.test/a\r\nforged"])->postJson('/client-errors', [
        'message' => "Boom\n2026-01-01 forged.ERROR: injected\x00\x1b[31m",
        'stack' => "at foo (app.tsx:1)\nat bar (app.tsx:2)\r\t",
        'componentStack' => "in RunPage\nin App",
    ])->assertNoContent();

    $log->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context['message'] === 'Boom2026-01-01 forged.ERROR: injected[31m'
            && $context['stack'] === 'at foo (app.tsx:1)at bar (app.tsx:2)'
            && $context['component_stack'] === 'in RunPagein App'
            && $context['url'] === 'https://temari.test/aforged',
    );
});

it('strips control characters from the message before ledgering', function (): void {
    spyOnClientErrorLog();
    $stack = 'at https://temari.test/build/assets/app-abc.js:1:1';

    $this->postJson('/client-errors', ['message' => "Boom\nforged", 'stack' => $stack])->assertNoContent();
    $this->postJson('/client-errors', ['message' => 'Boomforged', 'stack' => $stack])->assertNoContent();

    expect(NewExceptionLedger::pull())->toHaveCount(1);
});

it('throttles a browser stuck in an error loop', function (): void {
    spyOnClientErrorLog();

    foreach (range(1, 10) as $i) {
        $this->postJson('/client-errors', ['message' => "boom {$i}"])->assertNoContent();
    }

    $this->postJson('/client-errors', ['message' => 'boom 11'])->assertStatus(429);
});

it('leaves room for a real server exception after a guest flood, and stops the flood at the guest cap', function (): void {
    spyOnClientErrorLog();

    foreach (range(1, 11) as $host) {
        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$host}"])
                ->postJson('/client-errors', [
                    'message' => "visit evil.example {$host}-{$i}",
                    'stack' => "at https://evil.example/build/assets/free-shoes-{$host}-{$i}.js:1:1",
                ])->assertNoContent();
        }
    }
    NewExceptionLedger::recordServer(new RuntimeException('real failure'));

    $labels = array_column(NewExceptionLedger::pull(), 'label');

    expect($labels)->toHaveCount(1 + NewExceptionLedger::MAX_GUEST_PENDING)
        ->and($labels[0])->toStartWith('RuntimeException at ');
});
