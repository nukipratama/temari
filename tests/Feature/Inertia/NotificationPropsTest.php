<?php

declare(strict_types=1);

use App\Models\InboxNotification;
use App\Models\User;
use App\Services\Inertia\NotificationProps;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function notificationPropsFor(?User $user): array
{
    return app(NotificationProps::class)->forUser($user);
}

it('keeps the unread count a closure so a partial reload can skip it', function (): void {
    expect(notificationPropsFor(User::factory()->create())['unreadNotifications'])->toBeInstanceOf(Closure::class);
});

it('counts nothing unread for a guest', function (): void {
    expect((notificationPropsFor(null)['unreadNotifications'])())->toBe(0);
});

describe('unreadNotifications', function (): void {
    it('counts the unread rows and drops as they are read', function (): void {
        $user = User::factory()->create();
        InboxNotification::factory()->for($user)->count(3)->create();

        expect((notificationPropsFor($user)['unreadNotifications'])())->toBe(3);

        $user->inboxNotifications()->first()->markRead();

        expect((notificationPropsFor($user)['unreadNotifications'])())->toBe(2);
    });

    it('ignores another user\'s inbox', function (): void {
        $user = User::factory()->create();
        InboxNotification::factory()->count(2)->create();

        expect((notificationPropsFor($user)['unreadNotifications'])())->toBe(0);
    });
});
