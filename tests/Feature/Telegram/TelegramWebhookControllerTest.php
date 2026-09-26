<?php

declare(strict_types=1);

use App\Jobs\Telegram\HandleTelegramUpdateJob;
use App\Models\TelegramUpdateReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.webhook_secret' => 'top-secret']);
    Bus::fake();
});

it('dispatches the update job and acks when the secret token matches', function (): void {
    $updateId = random_int(1_000_000, 900_000_000);
    $update = ['update_id' => $updateId, 'message' => ['chat' => ['id' => 1], 'text' => '/start abc']];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
        ->postJson('/telegram/webhook', $update)
        ->assertOk()
        ->assertJson(['ok' => true]);

    Bus::assertDispatched(
        HandleTelegramUpdateJob::class,
        fn (HandleTelegramUpdateJob $job): bool => $job->update === $update,
    );
    $this->assertDatabaseHas('telegram_update_receipts', ['update_id' => $updateId]);
});

it('acks a replayed update without dispatching it again', function (): void {
    $updateId = random_int(1_000_000, 900_000_000);
    $update = ['update_id' => $updateId, 'message' => ['chat' => ['id' => 1], 'text' => '/start abc']];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
        ->postJson('/telegram/webhook', $update)
        ->assertOk()
        ->assertJson(['ok' => true]);
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'top-secret')
        ->postJson('/telegram/webhook', $update)
        ->assertOk()
        ->assertJson(['ok' => true]);

    Bus::assertDispatchedTimes(HandleTelegramUpdateJob::class, 1);
    expect(TelegramUpdateReceipt::query()->count())->toBe(1);
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
