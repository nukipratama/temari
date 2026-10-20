<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Gamification\SeasonPayloadBuilder;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
});
afterEach(fn () => Carbon::setTestNow());

function seasonPayloadBuilder(): SeasonPayloadBuilder
{
    return app(SeasonPayloadBuilder::class);
}

it('returns null season payloads when there is no season', function (): void {
    $user = User::factory()->create();

    expect(seasonPayloadBuilder()->seasonPayload(null, Carbon::today()))->toBeNull()
        ->and(seasonPayloadBuilder()->profileSeasonPayload($user, null, Carbon::today()))->toBeNull();
});

it('builds the Plan season frame without goals or record, given an ensured season', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $payload = seasonPayloadBuilder()->seasonPayload($season, Carbon::today());

    expect($payload)->not->toBeNull();
    assert($payload !== null);
    expect($payload)
        ->toHaveKeys(['starts_at', 'ends_at', 'week_index', 'total_weeks'])
        ->and($payload)->not->toHaveKeys(['goals', 'record', 'is_race_oriented', 'block_opens_on'])
        ->and($payload['week_index'])->toBe(1);
});

it('builds the Profile season with its resolved goals and none of the Plan-only fields', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $payload = seasonPayloadBuilder()->profileSeasonPayload($user, $season, Carbon::today());

    expect($payload)->not->toBeNull();
    assert($payload !== null);
    expect(array_keys($payload))->toBe(['starts_at', 'ends_at', 'goals'])
        ->and($payload['goals'])->toHaveCount(5)
        ->and($payload['goals'][0])->toHaveKeys(['id', 'title', 'current', 'target', 'unit', 'is_completed']);
});

it('counts season weeks as the plan\'s Monday weeks when the season opens mid-week', function (): void {
    Carbon::setTestNow('2026-09-05 08:00:00');
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());
    expect($season->starts_at->toDateString())->toBe('2026-09-05');

    $payload = seasonPayloadBuilder()->seasonPayload($season, Carbon::parse('2026-09-24'));

    expect($payload)->not->toBeNull();
    assert($payload !== null);
    expect($payload['week_index'])->toBe(4);
});
