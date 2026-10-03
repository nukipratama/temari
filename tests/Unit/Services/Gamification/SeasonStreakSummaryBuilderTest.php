<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->builder = app(SeasonStreakSummaryBuilder::class);
});
afterEach(fn () => Carbon::setTestNow());

it('returns null season payloads when there is no season', function (): void {
    $user = User::factory()->create();

    expect($this->builder->seasonPayload(null, Carbon::today()))->toBeNull()
        ->and($this->builder->profileSeasonPayload($user, null, Carbon::today()))->toBeNull();
});

it('builds the Plan season frame without goals or record, given an ensured season', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $payload = $this->builder->seasonPayload($season, Carbon::today());

    expect($payload)
        ->toHaveKeys(['starts_at', 'ends_at', 'week_index', 'total_weeks'])
        ->not->toHaveKeys(['goals', 'record', 'is_race_oriented', 'block_opens_on'])
        ->and($payload['week_index'])->toBe(1);
});

it('builds the Profile season with its resolved goals and none of the Plan-only fields', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $payload = $this->builder->profileSeasonPayload($user, $season, Carbon::today());

    expect($payload)->not->toBeNull()
        ->and(array_keys($payload))->toBe(['starts_at', 'ends_at', 'goals'])
        ->and($payload['goals'])->toHaveCount(5)
        ->and($payload['goals'][0])->toHaveKeys(['id', 'title', 'current', 'target', 'unit', 'is_completed']);
});

it('counts season weeks as the plan\'s Monday weeks when the season opens mid-week', function (): void {
    Carbon::setTestNow('2026-09-05 08:00:00');
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());
    expect($season->starts_at->toDateString())->toBe('2026-09-05');

    $payload = $this->builder->seasonPayload($season, Carbon::parse('2026-09-24'));

    expect($payload['week_index'])->toBe(4);
});
