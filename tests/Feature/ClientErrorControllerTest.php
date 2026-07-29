<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

function spyOnClientErrorLog(): void
{
    Log::spy()->shouldReceive('channel')->with('client-errors')->andReturnSelf();
}

it('logs a reported client error and returns no content', function (): void {
    spyOnClientErrorLog();

    $this->postJson('/client-errors', [
        'message' => 'Boom',
        'url' => 'https://temari.test/aktivitas',
        'stack' => 'at foo (app.tsx:1)',
        'componentStack' => 'in RunPage',
    ])->assertNoContent();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'client-error'
            && $context['message'] === 'Boom'
            && $context['url'] === 'https://temari.test/aktivitas',
    );
});

it('requires a message', function (): void {
    $this->postJson('/client-errors', [])->assertStatus(422);
});

it('is reachable without authentication (captures guest-page errors)', function (): void {
    $this->postJson('/client-errors', ['message' => 'guest boom'])->assertNoContent();
});

it('still accepts the longer traces a stale cached bundle sends', function (): void {
    spyOnClientErrorLog();

    $this->postJson('/client-errors', [
        'message' => 'Boom',
        'stack' => str_repeat('a', 5000),
        'componentStack' => str_repeat('b', 5000),
    ])->assertNoContent();
});

it('writes only the leading frames of an oversized trace', function (): void {
    spyOnClientErrorLog();

    $this->postJson('/client-errors', [
        'message' => 'Boom',
        'stack' => str_repeat('a', 5000),
        'componentStack' => str_repeat('b', 5000),
    ])->assertNoContent();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_starts_with((string) $context['stack'], str_repeat('a', 2000))
            && mb_strlen((string) $context['stack']) < 2100
            && mb_strlen((string) $context['component_stack']) < 2100,
    );
});

it('throttles a browser stuck in an error loop', function (): void {
    spyOnClientErrorLog();

    foreach (range(1, 10) as $i) {
        $this->postJson('/client-errors', ['message' => "boom {$i}"])->assertNoContent();
    }

    $this->postJson('/client-errors', ['message' => 'boom 11'])->assertStatus(429);
});
