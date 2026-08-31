<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use App\Enums\NotificationKind;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array<int, array<string, mixed>>
 */
function inboxRows(TestResponse $response): array
{
    /** @var array<int, array<string, mixed>> $data */
    $data = $response->viewData('page')['props']['notifications']['data'];

    return $data;
}

it('requires authentication', function (): void {
    $this->get('/inbox')->assertRedirect('/login');
});

it('lists the signed-in user rows newest first', function (): void {
    $user = User::factory()->create();
    $older = InboxNotification::factory()->for($user)->create(['title' => 'Older']);
    $newer = InboxNotification::factory()->for($user)->create(['title' => 'Newer']);
    InboxNotification::factory()->create(['title' => 'Someone else']);

    $response = $this->actingAs($user)->get('/inbox')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Inbox')->has('notifications.data', 2));

    expect(array_column(inboxRows($response), 'id'))->toBe([$newer->id, $older->id]);
});

it('ships read state as a timestamp and the created instant with its offset', function (): void {
    Carbon::setTestNow('2026-08-13 07:30:00');
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->read()->create();

    $row = inboxRows($this->actingAs($user)->get('/inbox'))[0];

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

    $row = inboxRows($this->actingAs($user)->get('/inbox'))[0];

    expect($row['url'])->toBe('https://temari.test/activities/42')
        ->and($row['run_card_id'])->toBe(99)
        ->and($row['rarity'])->toBe('epic');
});

it('leaves a row with no payload with no handles', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create(['kind' => NotificationKind::Test, 'payload' => null]);

    $row = inboxRows($this->actingAs($user)->get('/inbox'))[0];

    expect($row['url'])->toBeNull()
        ->and($row['run_card_id'])->toBeNull()
        ->and($row['rarity'])->toBeNull();
});

it('opens on the page holding the deep-linked row', function (): void {
    $user = User::factory()->create();
    $rows = InboxNotification::factory()->for($user)->count(25)->create();
    $oldest = $rows->first();

    $response = $this->actingAs($user)->get('/inbox?item='.$oldest->id)
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('focusId', $oldest->id));

    expect(array_column(inboxRows($response), 'id'))->toContain($oldest->id);
});

it('ignores a non-numeric deep-link target', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->create();

    $this->actingAs($user)->get('/inbox?item=nope')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('focusId', null)->has('notifications.data', 1));
});

it('paginates rather than growing without bound', function (): void {
    $user = User::factory()->create();
    InboxNotification::factory()->for($user)->count(21)->create();

    $this->actingAs($user)->get('/inbox')
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications.data', 20)
            ->where('notifications.last_page', 2));

    $this->actingAs($user)->get('/inbox?page=2')
        ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 1));
});
