<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Enums\NotificationKind;
use App\Models\InboxNotification;
use App\Models\User;
use App\Notifications\Messages\InboxMessage;
use App\Support\SharedPropCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function inboxMessage(string $title = 'Your run is in! 🏁'): InboxMessage
{
    return new InboxMessage(
        kind: NotificationKind::PostRun,
        title: $title,
        body: 'steady one.',
        payload: ['run_card_id' => 9, 'rarity' => 'rare'],
        subjectType: Activity::class,
        subjectId: 42,
    );
}

it('records a row with its kind, subject and replay payload intact', function (): void {
    $user = User::factory()->create();

    expect(InboxNotification::record($user, inboxMessage(), 'analysis:7'))->toBeTrue();

    $row = InboxNotification::query()->firstOrFail();

    expect($row->kind)->toBe(NotificationKind::PostRun)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->subject_type)->toBe(Activity::class)
        ->and($row->subject_id)->toBe(42)
        ->and($row->payload)->toEqual(['run_card_id' => 9, 'rarity' => 'rare'])
        ->and($row->read_at)->toBeNull();
});

it('ignores a second record for the same user and dedupe key', function (): void {
    $user = User::factory()->create();

    InboxNotification::record($user, inboxMessage(), 'analysis:7');

    expect(InboxNotification::record($user, inboxMessage('a later title'), 'analysis:7'))->toBeFalse()
        ->and(InboxNotification::query()->count())->toBe(1)
        ->and(InboxNotification::query()->value('title'))->toBe('Your run is in! 🏁');
});

it('lets two users share a dedupe key', function (): void {
    $one = User::factory()->create();
    $two = User::factory()->create();

    InboxNotification::record($one, inboxMessage(), 'analysis:7');

    expect(InboxNotification::record($two, inboxMessage(), 'analysis:7'))->toBeTrue()
        ->and(InboxNotification::query()->count())->toBe(2);
});

it('stores a null payload rather than an empty json object', function (): void {
    $user = User::factory()->create();

    InboxNotification::record($user, new InboxMessage(kind: NotificationKind::Test, title: 'Test'), 'test:1');

    expect(InboxNotification::query()->value('payload'))->toBeNull();
});

it('counts only unread rows', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->count(2)->create();
    InboxNotification::factory()->for($user)->read()->create();
    InboxNotification::factory()->create();

    expect(InboxNotification::unreadCountFor($user->id))->toBe(2);
});

it('marks a row read once and keeps the first timestamp', function (): void {
    $row = InboxNotification::factory()->create();

    $row->markRead();
    $first = $row->read_at;

    $this->travel(5)->minutes();
    $row->markRead();

    expect($row->fresh()->read_at->equalTo($first))->toBeTrue();
});

it('busts the unread-count shared prop on write and on read', function (): void {
    $user = User::factory()->create();
    $key = SharedPropCacheKey::UnreadNotifications->key($user->id);

    Cache::put($key, 99, 300);
    InboxNotification::record($user, inboxMessage(), 'analysis:7');
    expect(Cache::get($key))->toBeNull();

    Cache::put($key, 99, 300);
    InboxNotification::query()->firstOrFail()->markRead();
    expect(Cache::get($key))->toBeNull();
});

it('exposes the inbox on the user, newest first', function (): void {
    $user = User::factory()->create();
    $older = InboxNotification::factory()->for($user)->create(['created_at' => now()->subDay()]);
    $newer = InboxNotification::factory()->for($user)->create();

    expect($user->inboxNotifications()->pluck('id')->all())->toBe([$newer->id, $older->id]);
});
