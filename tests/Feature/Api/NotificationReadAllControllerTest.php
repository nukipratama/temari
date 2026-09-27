<?php

declare(strict_types=1);

use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks every unread row for the user read in one request', function (): void {
    $user = User::factory()->create();
    $rows = InboxNotification::factory()->for($user)->count(3)->create();

    $this->actingAs($user)
        ->postJson(route('api.notifications.read-all'))
        ->assertOk()
        ->assertJson(['unread' => 0]);

    $rows->each(fn (InboxNotification $row) => expect($row->fresh()->read_at)->not->toBeNull());
});

it('leaves an already-read row\'s timestamp alone', function (): void {
    $user = User::factory()->create();
    $readAt = now()->subDay()->startOfSecond();
    $row = InboxNotification::factory()->for($user)->create(['read_at' => $readAt]);

    $this->actingAs($user)->postJson(route('api.notifications.read-all'))->assertOk();

    expect($row->fresh()->read_at->equalTo($readAt))->toBeTrue();
});

it('never touches another user\'s unread rows', function (): void {
    $other = User::factory()->create();
    $row = InboxNotification::factory()->for($other)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('api.notifications.read-all'))
        ->assertOk()
        ->assertJson(['unread' => 0]);

    expect($row->fresh()->read_at)->toBeNull();
});

it('rejects a guest', function (): void {
    $this->postJson(route('api.notifications.read-all'))->assertUnauthorized();
});
