<?php

declare(strict_types=1);

use App\Enums\PrCategory;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->service = app(SeasonService::class);
});
afterEach(fn () => Carbon::setTestNow());

/** Everything the user owns, as the rows that actually record ownership. */
function ownedCollection(User $user): array
{
    return [
        'unlocks' => UserUnlock::query()->where('user_id', $user->id)->orderBy('unlock_key')->pluck('unlock_key')->all(),
        'records' => PersonalRecord::query()->where('user_id', $user->id)->orderBy('category')->pluck('category')->all(),
        'cards' => RunCard::query()
            ->join('activities', 'activities.id', '=', 'run_cards.activity_id')
            ->where('activities.user_id', $user->id)
            ->orderBy('run_cards.id')
            ->pluck('run_cards.rarity')
            ->all(),
    ];
}

function seedCollection(User $user): void
{
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_first']);
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.shirt_beginner']);
    PersonalRecord::factory()->for($user)->create(['category' => PrCategory::Km5]);
    RunCard::factory()->for(Activity::factory()->for($user))->create();
}

it('opens a new season with a fresh track once the twelve weeks elapse', function (): void {
    $user = User::factory()->create();

    $first = $this->service->ensureCurrent($user, Carbon::today());
    $firstGoalIds = SeasonGoal::query()->where('season_id', $first->id)->pluck('id')->all();

    $afterBoundary = $first->ends_at->copy()->addDay();
    Carbon::setTestNow($afterBoundary->copy()->setTime(8, 0));
    $second = $this->service->ensureCurrent($user, $afterBoundary);

    expect($second->id)->not->toBe($first->id)
        ->and($second->starts_at->toDateString())->toBe($afterBoundary->toDateString())
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(2);

    $secondGoalIds = SeasonGoal::query()->where('season_id', $second->id)->pluck('id')->all();

    expect($secondGoalIds)->toHaveCount(5)
        ->and(array_intersect($firstGoalIds, $secondGoalIds))->toBe([])
        ->and(SeasonGoal::query()->whereIn('id', $firstGoalIds)->count())->toBe(5);
});

it('revokes nothing the user owns when a season boundary is crossed', function (): void {
    $user = User::factory()->create();
    seedCollection($user);

    $first = $this->service->ensureCurrent($user, Carbon::today());
    $before = ownedCollection($user);

    $afterBoundary = $first->ends_at->copy()->addDay();
    Carbon::setTestNow($afterBoundary->copy()->setTime(8, 0));
    $this->service->ensureCurrent($user, $afterBoundary);

    expect(ownedCollection($user))->toBe($before)
        ->and($before['unlocks'])->toHaveCount(2)
        ->and($before['records'])->toHaveCount(1)
        ->and($before['cards'])->toHaveCount(1);
});

it('keeps a previous season\'s per-season rewards while leaving the new season re-earnable', function (): void {
    $user = User::factory()->create();

    $first = $this->service->ensureCurrent($user, Carbon::today());
    UserUnlock::factory()->for($user)->create(['unlock_key' => "season.{$first->id}.rest_honored_3"]);

    $afterBoundary = $first->ends_at->copy()->addDay();
    Carbon::setTestNow($afterBoundary->copy()->setTime(8, 0));
    $second = $this->service->ensureCurrent($user, $afterBoundary);

    $keys = UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all();

    expect($keys)->toContain("season.{$first->id}.rest_honored_3")
        ->and($keys)->not->toContain("season.{$second->id}.rest_honored_3");
});
