<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Models\Activity;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        'badges' => [Badge::AnakPagi->value, Badge::NegativeSplit->value],
    ]);
    RunCard::factory()->for(Activity::factory()->for($user))->create([
        'badges' => [Badge::AnakPagi->value],
    ]);

    $counts = RunCard::badgeCountsForUser($user->id);

    expect($counts[Badge::AnakPagi->value])->toBe(2)
        ->and($counts[Badge::NegativeSplit->value])->toBe(1)
        ->and($counts[Badge::HariPanas->value])->toBe(0);
});

it('badgeCountsForUser ignores untracked badge values', function (): void {
    $user = User::factory()->create();
    RunCard::factory()->for(Activity::factory()->for($user))->create([
        'badges' => ['not_a_tracked_badge', Badge::AnakPagi->value],
    ]);

    $counts = RunCard::badgeCountsForUser($user->id);

    expect($counts[Badge::AnakPagi->value])->toBe(1)
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
        'badges' => [Badge::AnakPagi->value],
    ]);

    expect(RunCard::badgeCountsForUser($user->id)[Badge::AnakPagi->value])->toBe(0);
});

it('casts badges to an array', function (): void {
    $card = RunCard::factory()->make([
        'activity_id' => 1,
        'badges' => ['hari_panas', 'negative_split'],
    ]);

    expect($card->badges)->toBe(['hari_panas', 'negative_split']);
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
