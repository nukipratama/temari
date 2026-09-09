<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Enums\Rarity;
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

it('firstEarnedBadgesForUser returns the earliest date and rarity each badge was earned at', function (): void {
    $user = User::factory()->create();

    $earlier = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($earlier)->create(['start_date_local' => '2026-01-05 07:00:00']);
    RunCard::factory()->for($earlier)->create(['badges' => [Badge::EarlyBird->value], 'rarity' => Rarity::Rare]);

    $later = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($later)->create(['start_date_local' => '2026-03-10 07:00:00']);
    RunCard::factory()->for($later)->create([
        'badges' => [Badge::EarlyBird->value, Badge::Speedster->value],
        'rarity' => Rarity::Epic,
    ]);

    $first = RunCard::firstEarnedBadgesForUser($user->id);

    expect($first[Badge::EarlyBird->value]['date'])->toStartWith('2026-01-05')
        ->and($first[Badge::EarlyBird->value]['rarity'])->toBe(Rarity::Rare->value)
        ->and($first[Badge::Speedster->value]['date'])->toStartWith('2026-03-10')
        ->and($first[Badge::Speedster->value]['rarity'])->toBe(Rarity::Epic->value);
});

it('firstEarnedBadgesForUser ignores a card whose activity is not yet analyzed', function (): void {
    $user = User::factory()->create();
    $stub = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($stub)->create(['start_date_local' => '2026-01-05 07:00:00']);
    RunCard::factory()->for($stub)->create(['badges' => [Badge::EarlyBird->value]]);

    expect(RunCard::firstEarnedBadgesForUser($user->id))->toBe([]);
});

it('firstEarnedBadgesForUser scopes to the given user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-01-05 07:00:00']);
    RunCard::factory()->for($activity)->create(['badges' => [Badge::EarlyBird->value]]);

    expect(RunCard::firstEarnedBadgesForUser($user->id))->toBe([]);
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
