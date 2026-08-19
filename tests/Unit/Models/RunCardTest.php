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

it('badgeCountsForUser counts each tracked badge across the user\'s cards', function (): void {
    $user = User::factory()->create();
    RunCard::factory()->for(Activity::factory()->for($user))->create([
        'badges' => [Badge::EarlyBird->value, Badge::NegativeSplit->value],
    ]);
    RunCard::factory()->for(Activity::factory()->for($user))->create([
        'badges' => [Badge::EarlyBird->value],
    ]);

    $counts = RunCard::badgeCountsForUser($user->id);

    expect($counts[Badge::EarlyBird->value])->toBe(2)
        ->and($counts[Badge::NegativeSplit->value])->toBe(1)
        ->and($counts[Badge::HeatTamer->value])->toBe(0);
});

it('badgeCountsForUser ignores untracked badge values', function (): void {
    $user = User::factory()->create();
    RunCard::factory()->for(Activity::factory()->for($user))->create([
        'badges' => ['not_a_tracked_badge', Badge::EarlyBird->value],
    ]);

    $counts = RunCard::badgeCountsForUser($user->id);

    expect($counts[Badge::EarlyBird->value])->toBe(1)
        ->and($counts)->not->toHaveKey('not_a_tracked_badge');
});

it('badgeCountsForUser returns every tracked badge at zero for a user with no cards', function (): void {
    $user = User::factory()->create();

    $counts = RunCard::badgeCountsForUser($user->id);

    foreach (Badge::tracked() as $badge) {
        expect($counts[$badge->value])->toBe(0);
    }
});

it('badgeCountsForUser scopes to the given user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    RunCard::factory()->for(Activity::factory()->for($other))->create([
        'badges' => [Badge::EarlyBird->value],
    ]);

    expect(RunCard::badgeCountsForUser($user->id)[Badge::EarlyBird->value])->toBe(0);
});

it('allBadgeCountsForUser counts every badge case, not just tracked ones', function (): void {
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

it('firstEarnedDatesForUser returns the earliest date each badge was earned', function (): void {
    $user = User::factory()->create();

    $earlier = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($earlier)->create(['start_date_local' => '2026-01-05 07:00:00']);
    RunCard::factory()->for($earlier)->create(['badges' => [Badge::EarlyBird->value]]);

    $later = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($later)->create(['start_date_local' => '2026-03-10 07:00:00']);
    RunCard::factory()->for($later)->create(['badges' => [Badge::EarlyBird->value, Badge::Speedster->value]]);

    $firstDates = RunCard::firstEarnedDatesForUser($user->id);

    expect($firstDates[Badge::EarlyBird->value])->toStartWith('2026-01-05')
        ->and($firstDates[Badge::Speedster->value])->toStartWith('2026-03-10');
});

it('firstEarnedDatesForUser scopes to the given user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-01-05 07:00:00']);
    RunCard::factory()->for($activity)->create(['badges' => [Badge::EarlyBird->value]]);

    expect(RunCard::firstEarnedDatesForUser($user->id))->toBe([]);
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
