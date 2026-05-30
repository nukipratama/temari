<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Freeze today so blueprint subDays() anchors and ISO-week math are stable.
beforeEach(fn () => Carbon::setTestNow('2026-05-12 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('creates the demo user, runs, cards, story lines, PRs, and weekly snapshots', function (): void {
    $exitCode = $this->artisan('demo:seed', ['--fresh' => true])->run();

    expect($exitCode)->toBe(0);

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();

    // 26 scripted + RNG fillers @ 65% over ~180d; exact match fails loud on drift.
    $activityCount = Activity::query()->where('user_id', $user->id)->count();
    expect($activityCount)->toBe(122);

    expect(RunCard::query()->whereIn('activity_id', Activity::query()->where('user_id', $user->id)->pluck('id'))->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_POST_RUN)->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_DAILY_GREETING)->count())
        ->toBe(1)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(27)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(9);
});

it('seeds a full-featured, login-ready demo: rarity ladder, unlocks, persona, varied maps', function (): void {
    // One full seed feeds every assertion below — the seed is heavy (122 runs),
    // so completeness checks share it rather than re-seeding per concern.
    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();
    $cardQuery = RunCard::query()->whereHas('activity', fn ($q) => $q->where('user_id', $user->id));

    expect((clone $cardQuery)->where('rarity', Rarity::Legendary)->count())->toBeGreaterThanOrEqual(1)
        ->and((clone $cardQuery)->where('rarity', Rarity::Epic)->count())->toBeGreaterThanOrEqual(3);

    // Every defined accessory should unlock from the seeded dataset.
    $unlocked = UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all();
    expect($unlocked)->toContain(
        'accessory.medal_first_pr',
        'accessory.medal_gold',
        'accessory.headband_legendaris',
        'accessory.headband_epik',
        'accessory.weekly_streak_4',
    );

    // Best-in-slot accessories are equipped so the demo mascot shows them off.
    $equipped = UserUnlock::query()->where('user_id', $user->id)->where('equipped', true)->pluck('unlock_key')->all();
    expect($equipped)->toContain('accessory.headband_legendaris', 'accessory.medal_gold', 'accessory.weekly_streak_4');

    $persona = Analysis::query()
        ->where('subject_type', AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('discriminator', Carbon::now()->isoFormat('GGGG-[W]WW'))
        ->first();
    expect($persona)->not->toBeNull()
        ->and($persona->status->value)->toBe('done')
        ->and($persona->content)->not->toBeEmpty();

    $distinctLocations = ActivityDetail::query()
        ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
        ->where('activities.user_id', $user->id)
        ->whereNotNull('activity_details.location_name')
        ->distinct()
        ->count('activity_details.location_name');
    expect($distinctLocations)->toBeGreaterThan(1);
});

it('queues the reveal modal on the rarest seeded card', function (): void {
    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();

    expect($user->pending_reveal_card_id)->not->toBeNull();

    $queued = RunCard::query()->findOrFail($user->pending_reveal_card_id);
    $maxRank = RunCard::query()
        ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
        ->get()
        ->max(fn (RunCard $card): int => $card->rarity->rank());

    // The queued reveal is one of the rarest cards (legendary, here).
    expect($queued->rarity->rank())->toBe($maxRank);
});

it('is idempotent — re-running with --fresh produces a consistent row count', function (): void {
    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $firstUser = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();
    $first = Activity::query()->where('user_id', $firstUser->id)->count();

    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $secondUser = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();
    $second = Activity::query()->where('user_id', $secondUser->id)->count();

    expect($second)->toBe($first);
});
