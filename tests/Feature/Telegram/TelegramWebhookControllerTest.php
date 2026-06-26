<?php

declare(strict_types=1);

use App\Jobs\Telegram\HandleTelegramUpdateJob;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config(['services.telegram.webhook_secret' => 'top-secret']);
    Bus::fake();
});

it('dispatches the update job and acks when the secret token matches', function (): void {
    $update = ['message' => ['chat' => ['id' => 1], 'text' => '/start abc']];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
        ->postJson('/telegram/webhook', $update)
        ->assertOk()
        ->assertJson(['ok' => true]);

    Bus::assertDispatched(
        HandleTelegramUpdateJob::class,
        fn (HandleTelegramUpdateJob $job): bool => $job->update === $update,
    );
});

it('rejects a request whose secret token does not match', function (): void {
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')
        ->postJson('/telegram/webhook', ['message' => []])
        ->assertForbidden();

    Bus::assertNothingDispatched();
});

it('rejects when no secret token header is sent', function (): void {
    $this->postJson('/telegram/webhook', ['message' => []])
        ->assertForbidden();

    Bus::assertNothingDispatched();
});
