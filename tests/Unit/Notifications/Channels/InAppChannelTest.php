<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\Channels\InAppChannel;
use App\Notifications\Messages\InboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;

uses(RefreshDatabase::class);

class InboxlessNotification extends Notification
{
}

class KeyedInboxNotification extends Notification
{
    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(
            kind: NotificationKind::Unlock,
            title: 'Unlocked: Legendary Shoes',
            payload: ['unlock_key' => 'accessory.shoes_legendary'],
            dedupeKey: 'unlock:accessory.shoes_legendary',
        );
    }
}

class UnkeyedInboxNotification extends Notification
{
    public function toInbox(User $notifiable): InboxMessage
    {
        return new InboxMessage(kind: NotificationKind::Test, title: 'Test notification');
    }
}

class NullInboxNotification extends Notification
{
    public function toInbox(User $notifiable): ?InboxMessage
    {
        return null;
    }
}

it('writes the row a notification describes', function (): void {
    $user = User::factory()->create();

    new InAppChannel()->send($user, new KeyedInboxNotification());

    $row = InboxNotification::query()->firstOrFail();

    expect($row->kind)->toBe(NotificationKind::Unlock)
        ->and($row->title)->toBe('Unlocked: Legendary Shoes')
        ->and($row->dedupe_key)->toBe('unlock:accessory.shoes_legendary');
});

it('ignores a notification with no inbox message', function (): void {
    new InAppChannel()->send(User::factory()->create(), new InboxlessNotification());

    expect(InboxNotification::query()->count())->toBe(0);
});

it('ignores a notification whose inbox message resolves to null', function (): void {
    new InAppChannel()->send(User::factory()->create(), new NullInboxNotification());

    expect(InboxNotification::query()->count())->toBe(0);
});

// Laravel assigns the notification id before queueing, so it survives a retry
// unchanged — which is what makes it a safe fallback dedupe key.
it('falls back to the notification id when the message supplies no key', function (): void {
    $user = User::factory()->create();
    $notification = new UnkeyedInboxNotification();
    $notification->id = 'fixed-uuid';

    new InAppChannel()->send($user, $notification);
    new InAppChannel()->send($user, $notification);

    expect(InboxNotification::query()->count())->toBe(1)
        ->and(InboxNotification::query()->value('dedupe_key'))->toBe('fixed-uuid');
});
