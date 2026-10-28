<?php

declare(strict_types=1);

use App\Http\Middleware\MarkPushedInboxRowRead;
use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function landFromPush(?User $user, string $uri, string $method = 'GET'): void
{
    $request = Request::create($uri, $method);
    $request->setUserResolver(fn (): ?User => $user);

    app(MarkPushedInboxRowRead::class)->handle($request, fn (): Response => new Response());
}

it('marks the tapped push row read', function (): void {
    $user = User::factory()->create();
    $row = InboxNotification::factory()->for($user)->create();

    landFromPush($user, "/race?read={$row->id}");

    expect($row->refresh()->read_at)->not->toBeNull();
});

it('leaves another athlete\'s row alone', function (): void {
    $row = InboxNotification::factory()->create();

    landFromPush(User::factory()->create(), "/?read={$row->id}");

    expect($row->refresh()->read_at)->toBeNull();
});

it('ignores a guest, a non-numeric id and a non-GET request', function (string $method, string $query, bool $signedIn): void {
    $user = User::factory()->create();
    $row = InboxNotification::factory()->for($user)->create();

    landFromPush($signedIn ? $user : null, '/?read=' . str_replace('ID', (string) $row->id, $query), $method);

    expect($row->refresh()->read_at)->toBeNull();
})->with([
    'guest' => ['GET', 'ID', false],
    'non-numeric' => ['GET', 'ID,abc', true],
    'post' => ['POST', 'ID', true],
]);

it('ships the lowered unread count in the very page it lands on', function (): void {
    $user = User::factory()->create();
    $rows = InboxNotification::factory()->for($user)->count(2)->create();

    $this->actingAs($user)
        ->get('/inbox?read=' . $rows->first()?->id)
        ->assertInertia(fn (Assert $page): Assert => $page->where('unreadNotifications', 1));
});
