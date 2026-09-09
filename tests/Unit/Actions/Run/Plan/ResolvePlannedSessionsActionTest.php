<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolvePlannedSessionsAction;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the range, oldest first', function (): void {
    $user = User::factory()->create();
    foreach (['2026-09-05', '2026-09-07', '2026-09-20'] as $date) {
        PlannedSession::factory()->for($user)->create(['date' => $date]);
    }

    $sessions = (new ResolvePlannedSessionsAction())($user->id, '2026-09-01', '2026-09-10');

    expect($sessions->pluck('date')->map->toDateString()->all())->toBe(['2026-09-05', '2026-09-07']);
});

it('serves a narrower range from a window already read', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-05']);
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-09']);

    $resolve = new ResolvePlannedSessionsAction();
    $resolve($user->id, '2026-08-17', '2026-09-13');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $narrow = $resolve($user->id, '2026-09-08', '2026-09-10');

    expect($queries)->toBe(0)
        ->and($narrow->pluck('date')->map->toDateString()->all())->toBe(['2026-09-09']);
});

it('reads again for a range the memo does not cover', function (): void {
    $user = User::factory()->create();
    $resolve = new ResolvePlannedSessionsAction();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $resolve($user->id, '2026-09-01', '2026-09-10');
    $resolve($user->id, '2026-09-01', '2026-09-20');

    expect($queries)->toBe(2);
});

it('keeps athletes apart', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    PlannedSession::factory()->for($first)->create(['date' => '2026-09-05']);

    $resolve = new ResolvePlannedSessionsAction();

    expect($resolve($first->id, '2026-09-01', '2026-09-10'))->toHaveCount(1)
        ->and($resolve($second->id, '2026-09-01', '2026-09-10'))->toHaveCount(0);
});

it('drops the shared memo when a session is saved or deleted', function (): void {
    $user = User::factory()->create();
    $resolve = app(ResolvePlannedSessionsAction::class);

    expect($resolve($user->id, '2026-09-01', '2026-09-10'))->toHaveCount(0);

    $session = PlannedSession::factory()->for($user)->create(['date' => '2026-09-05']);
    expect($resolve($user->id, '2026-09-01', '2026-09-10'))->toHaveCount(1);

    $session->delete();
    expect($resolve($user->id, '2026-09-01', '2026-09-10'))->toHaveCount(0);
});

it('re-reads after forget', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-05']);

    $resolve = new ResolvePlannedSessionsAction();
    expect($resolve($user->id, '2026-09-01', '2026-09-10'))->toHaveCount(1);

    PlannedSession::query()->where('user_id', $user->id)->delete();
    $resolve->forget($user->id);

    expect($resolve($user->id, '2026-09-01', '2026-09-10'))->toHaveCount(0);
});

it('is one shared instance per request', function (): void {
    expect(app(ResolvePlannedSessionsAction::class))->toBe(app(ResolvePlannedSessionsAction::class));
});
