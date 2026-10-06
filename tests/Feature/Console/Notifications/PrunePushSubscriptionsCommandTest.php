<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pushSubscriptionSeen(User $user, string $endpoint, int $daysAgo): void
{
    $user->updatePushSubscription($endpoint, 'k', 't')
        ->forceFill(['last_seen_at' => now()->subDays($daysAgo)])
        ->save();
}

it('deletes the subscription a reinstall left behind once it has gone unseen for 60 days', function (): void {
    $user = User::factory()->create();
    pushSubscriptionSeen($user, 'https://web.push.apple.com/deleted-install', 61);
    pushSubscriptionSeen($user, 'https://web.push.apple.com/reinstalled', 0);

    $this->artisan('notifications:prune-push-subscriptions')
        ->expectsOutput('Pruned 1 push subscriptions unseen for 60 days.')
        ->assertSuccessful();

    expect($user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://web.push.apple.com/reinstalled']);
});

it('keeps a device seen within the last 60 days', function (): void {
    $user = User::factory()->create();
    pushSubscriptionSeen($user, 'https://web.push.apple.com/ipad', 59);

    $this->artisan('notifications:prune-push-subscriptions')->assertSuccessful();

    expect($user->pushSubscriptions()->count())->toBe(1);
});
