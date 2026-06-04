<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

it('logs a reported client error and returns no content', function (): void {
    Log::spy();

    $this->postJson('/client-errors', [
        'message' => 'Boom',
        'url' => 'https://teman-lari.test/aktivitas',
        'stack' => 'at foo (app.tsx:1)',
        'componentStack' => 'in RunPage',
    ])->assertNoContent();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'client-error'
            && $context['message'] === 'Boom'
            && $context['url'] === 'https://teman-lari.test/aktivitas',
    );
});

it('requires a message', function (): void {
    $this->postJson('/client-errors', [])->assertStatus(422);
});

it('is reachable without authentication (captures guest-page errors)', function (): void {
    $this->postJson('/client-errors', ['message' => 'guest boom'])->assertNoContent();
});
