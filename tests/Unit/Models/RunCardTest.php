<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('forUser scopes to cards whose activity belongs to the user', function (): void {
    $user = User::factory()->create();
    $mine = RunCard::factory()->for(Activity::factory()->for($user))->create();
    RunCard::factory()->create(); // another user

    expect(RunCard::query()->forUser($user->id)->pluck('id')->all())->toBe([$mine->id]);
});

it('allBadgeCountsForUser counts every badge case', function (): void {
    $user = User::factory()->create();
    RunCard::factory()->for(Activity::factory()->for($user))->create([
        'badges' => [Badge::Speedster->value, Badge::EarlyBird->value],
    ]);

    $counts = RunCard::allBadgeCountsForUser($user->id);

    expect($counts[Badge::Speedster->value])->toBe(1)
        ->and($counts[Badge::EarlyBird->value])->toBe(1)
        ->and($counts)->toHaveCount(count(Badge::cases()));
});

it('allBadgeCountsForUser scopes to a date range when given one', function (): void {
    $user = User::factory()->create();
    $inRange = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($inRange)->create(['start_date_local' => '2026-06-15 07:00:00']);
    RunCard::factory()->for($inRange)->create(['badges' => [Badge::Speedster->value]]);

    $outOfRange = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($outOfRange)->create(['start_date_local' => '2026-01-01 07:00:00']);
    RunCard::factory()->for($outOfRange)->create(['badges' => [Badge::Speedster->value]]);

    $counts = RunCard::allBadgeCountsForUser(
        $user->id,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
    );

    expect($counts[Badge::Speedster->value])->toBe(1);
});

it('casts badges to an array', function (): void {
    $card = RunCard::factory()->make([
        'activity_id' => 1,
        'badges' => ['heat_tamer', 'negative_split'],
    ]);

    expect($card->badges)->toBe(['heat_tamer', 'negative_split']);
});

it('belongs to an activity and enforces one card per activity', function (): void {
    $activity = Activity::factory()->create();
    RunCard::factory()->for($activity)->create();

    expect(fn () => RunCard::factory()->for($activity)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('cascades deletion from activity', function (): void {
    $card = RunCard::factory()->create();
    $activityId = $card->activity_id;

    Activity::query()->whereKey($activityId)->delete();

    expect(RunCard::query()->find($card->id))->toBeNull();
});
