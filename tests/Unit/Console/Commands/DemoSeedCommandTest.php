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

it('seeds a complete, login-ready demo dataset and stays idempotent across re-runs', function (): void {
    // The seed is heavy (~126 runs) and deterministic (frozen clock). It is the
    // suite's single slowest unit of work, so this file runs it as few times as
    // possible: ONE seed feeds every completeness assertion below, then a SECOND
    // bare seed proves idempotency — two seeds total, not the original five.
    $exitCode = $this->artisan('demo:seed')->run();
    expect($exitCode)->toBe(0);

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();

    // Core row counts — 35 scripted + RNG fillers @ 65% over ~180d; exact match fails loud on drift.
    $activityIds = Activity::query()->where('user_id', $user->id)->pluck('id');
    $activityCount = $activityIds->count();
    expect($activityCount)->toBe(126)
        ->and(RunCard::query()->whereIn('activity_id', $activityIds)->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_POST_RUN)->count())
        ->toBe($activityCount)
        ->and(StoryLine::query()->where('user_id', $user->id)->where('kind', StoryLine::KIND_DAILY_GREETING)->count())
        ->toBe(1)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(27)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe(11);

    // Rarity ladder — the seeded dataset spans up to legendary.
    $cardQuery = RunCard::query()->whereHas('activity', fn ($q) => $q->where('user_id', $user->id));
    expect((clone $cardQuery)->where('rarity', Rarity::Legendary)->count())->toBeGreaterThanOrEqual(1)
        ->and((clone $cardQuery)->where('rarity', Rarity::Epic)->count())->toBeGreaterThanOrEqual(3);

    // Every defined accessory unlocks; best-in-slot ones are equipped for the mascot.
    $unlocked = UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all();
    expect($unlocked)->toContain(
        'accessory.medal_pertama',
        'accessory.medal_emas',
        'accessory.ikat_kepala_legendaris',
        'accessory.ikat_kepala_epik',
    );
    $equipped = UserUnlock::query()->where('user_id', $user->id)->where('equipped', true)->pluck('unlock_key')->all();
    expect($equipped)->toContain('accessory.ikat_kepala_legendaris', 'accessory.medal_emas');

    // Persona summary is backfilled to a done analysis row.
    $persona = Analysis::query()
        ->where('subject_type', AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('discriminator', Carbon::now()->isoFormat('GGGG-[W]WW'))
        ->first();
    expect($persona)->not->toBeNull()
        ->and($persona->status->value)->toBe('done')
        ->and($persona->content)->not->toBeEmpty();

    // Varied maps: more than one distinct resolved location.
    $distinctLocations = ActivityDetail::query()
        ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
        ->where('activities.user_id', $user->id)
        ->whereNotNull('activity_details.location_name')
        ->distinct()
        ->count('activity_details.location_name');
    expect($distinctLocations)->toBeGreaterThan(1);

    // The reveal modal is queued on one of the rarest seeded cards (legendary, here).
    expect($user->pending_reveal_card_id)->not->toBeNull();
    $queued = RunCard::query()->findOrFail($user->pending_reveal_card_id);
    $maxRank = (clone $cardQuery)->get()->max(fn (RunCard $card): int => $card->rarity->rank());
    expect($queued->rarity->rank())->toBe($maxRank);

    // Idempotency: a second *bare* seed (no wipe) converges to the same row counts
    // instead of duplicating or hitting the (user_id, strava_external_id) unique
    // constraint. Every row is keyed on a deterministic identity via updateOrCreate.
    $cardCount = RunCard::query()->whereIn('activity_id', $activityIds)->count();
    $snapshotCount = WeeklySnapshot::query()->where('user_id', $user->id)->count();
    $prCount = PersonalRecord::query()->where('user_id', $user->id)->count();

    $this->artisan('demo:seed')->assertSuccessful();

    $reseededActivityIds = Activity::query()->where('user_id', $user->id)->pluck('id');
    expect(User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->count())->toBe(1)
        ->and($reseededActivityIds)->toHaveCount($activityCount)
        ->and(RunCard::query()->whereIn('activity_id', $reseededActivityIds)->count())->toBe($cardCount)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe($snapshotCount)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe($prCount);
});
