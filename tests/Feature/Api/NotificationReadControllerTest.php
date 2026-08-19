<?php

declare(strict_types=1);

use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks a row read and reports the remaining unread count', function (): void {
    $user = User::factory()->create();
    $row = InboxNotification::factory()->for($user)->create();
    InboxNotification::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('api.notifications.read', $row->id))
        ->assertOk()
        ->assertJson(['unread' => 1]);

    expect($row->fresh()->read_at)->not->toBeNull();
});

it('404s on another user\'s row rather than confirming it exists', function (): void {
    $row = InboxNotification::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('api.notifications.read', $row->id))
        ->assertNotFound();

    expect($row->fresh()->read_at)->toBeNull();
});

it('rejects a guest', function (): void {
    $row = InboxNotification::factory()->create();

    $this->postJson(route('api.notifications.read', $row->id))->assertUnauthorized();
});
