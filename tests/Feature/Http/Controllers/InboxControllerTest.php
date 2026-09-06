<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * The deferred row window, fetched the way `<Deferred>` fetches it.
 *
 * @param  object  $actingAs  The authenticated test case.
 * @return array<int, array<string, mixed>>
 */
function inboxRows(object $actingAs, string $url = '/inbox'): array
{
    /** @var array<int, array<string, mixed>> $data */
    $data = $actingAs->get($url, inertiaPartialHeaders($actingAs, $url, 'Inbox', 'notifications'))
        ->assertSuccessful()
        ->json('props.notifications');

    return $data;
}

it('requires authentication', function (): void {
    $this->get('/inbox')->assertRedirect('/login');
});

it('paints the shell with every heavy block deferred', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create();

    $this->actingAs($user)->get('/inbox')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Inbox')
            ->where('focusId', null)
            ->missing('notifications')
            ->missing('shown')
            ->missing('hasOlder')
            ->etc());
});

it('lists the signed-in user rows newest first', function (): void {
    $user = User::factory()->create();
    $older = InboxNotification::factory()->for($user)->create(['title' => 'Older']);
    $newer = InboxNotification::factory()->for($user)->create(['title' => 'Newer']);
    InboxNotification::factory()->create(['title' => 'Someone else']);

    $this->actingAs($user)
        ->get('/inbox', inertiaPartialHeaders($this->actingAs($user), '/inbox', 'Inbox', 'notifications'))
        ->assertSuccessful()
        ->assertJsonPath('component', 'Inbox')
        ->assertJsonCount(2, 'props.notifications');

    expect(array_column(inboxRows($this->actingAs($user)), 'id'))->toBe([$newer->id, $older->id]);
});

it('ships read state as a timestamp and the created instant with its offset', function (): void {
    Carbon::setTestNow('2026-08-13 07:30:00');
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->read()->create();

    $row = inboxRows($this->actingAs($user))[0];

    expect($row['read_at'])->not->toBeNull()
        ->and($row['created_at'])->toBe(Carbon::parse('2026-08-13 07:30:00')->toIso8601String())
        ->and($row['created_at'])->toContain('+07:00');

    Carbon::setTestNow();
});

it('flattens the post-run replay handles out of the payload', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create([
        'kind' => NotificationKind::PostRun,
        'payload' => [
            'analysis_id' => 7,
            'url' => 'https://temari.test/activities/42',
            'activity_id' => 42,
            'run_card_id' => 99,
            'rarity' => 'epic',
        ],
    ]);

    $row = inboxRows($this->actingAs($user))[0];

    expect($row['url'])->toBe('https://temari.test/activities/42')
        ->and($row['run_card_id'])->toBe(99)
        ->and($row['rarity'])->toBe('epic');
});

it('leaves a row with no payload with no handles', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create(['kind' => NotificationKind::Test, 'payload' => null]);

    $row = inboxRows($this->actingAs($user))[0];

    expect($row['url'])->toBeNull()
        ->and($row['run_card_id'])->toBeNull()
        ->and($row['rarity'])->toBeNull();
});

it('opens on the page holding the deep-linked row', function (): void {
    $user = User::factory()->create();
    $rows = InboxNotification::factory()->for($user)->count(25)->create();
    $oldest = $rows->first();

    $this->actingAs($user)->get('/inbox?item='.$oldest->id)
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('focusId', $oldest->id));

    expect(array_column(inboxRows($this->actingAs($user), '/inbox?item='.$oldest->id), 'id'))
        ->toContain($oldest->id);
});

it('ignores a non-numeric deep-link target', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create();

    $this->actingAs($user)->get('/inbox?item=nope')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('focusId', null));

    expect(inboxRows($this->actingAs($user), '/inbox?item=nope'))->toHaveCount(1);
});

it('ships a bounded first window and says older rows sit behind it', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->count(21)->create();

    $this->actingAs($user)
        ->get('/inbox', inertiaPartialHeaders($this->actingAs($user), '/inbox', 'Inbox', 'notifications,shown,hasOlder'))
        ->assertJsonCount(20, 'props.notifications')
        ->assertJsonPath('props.shown', 20)
        ->assertJsonPath('props.hasOlder', true);
});

it('widens the window on request and drops the older flag at the end', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->count(21)->create();

    $this->actingAs($user)
        ->get('/inbox?shown=40', inertiaPartialHeaders($this->actingAs($user), '/inbox?shown=40', 'Inbox', 'notifications,shown,hasOlder'))
        ->assertJsonCount(21, 'props.notifications')
        ->assertJsonPath('props.shown', 40)
        ->assertJsonPath('props.hasOlder', false);
});

it('snaps a hand-typed window size up to the page step and caps it', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->count(3)->create();

    $this->actingAs($user)
        ->get('/inbox?shown=25', inertiaPartialHeaders($this->actingAs($user), '/inbox?shown=25', 'Inbox', 'shown'))
        ->assertJsonPath('props.shown', 40);

    $this->actingAs($user)
        ->get('/inbox?shown=99999', inertiaPartialHeaders($this->actingAs($user), '/inbox?shown=99999', 'Inbox', 'shown'))
        ->assertJsonPath('props.shown', 500);
});

it('reads an unlock rarity out of the catalog rather than the payload', function (): void {
    config()->set('temari_unlocks.accessory.medal_gold', ['name' => 'Gold Medal', 'rarity' => 'rare']);

    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create([
        'kind' => NotificationKind::Unlock,
        'payload' => ['unlock_key' => 'accessory.medal_gold', 'name' => 'Gold Medal'],
    ]);

    expect(inboxRows($this->actingAs($user))[0]['rarity'])->toBe('rare');
});

it('leaves an unlock with no catalog entry unrated', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create([
        'kind' => NotificationKind::Unlock,
        'payload' => ['unlock_key' => 'season.9.track_3'],
    ]);

    expect(inboxRows($this->actingAs($user))[0]['rarity'])->toBeNull();
});

it('carries distance and moving time for a post-run row', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['distance' => 6400.5, 'moving_time' => 1868]);
    InboxNotification::factory()->for($user)->create([
        'kind' => NotificationKind::PostRun,
        'payload' => ['activity_id' => $activity->id],
    ]);

    $row = inboxRows($this->actingAs($user))[0];

    expect($row['distance_m'])->toBe(6400.5)
        ->and($row['moving_time_s'])->toBe(1868);
});

it('leaves a recap row without run stats', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create([
        'kind' => NotificationKind::WeeklyRecap,
        'payload' => ['url' => 'https://temari.test/history'],
    ]);

    $row = inboxRows($this->actingAs($user))[0];

    expect($row['distance_m'])->toBeNull()
        ->and($row['moving_time_s'])->toBeNull();
});
