<?php

declare(strict_types=1);

use App\Services\Gamification\EquippedAccessories;
use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Freeze today so blueprint subDays() anchors and ISO-week math are stable.
beforeEach(fn () => Carbon::setTestNow('2026-05-12 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('seeds a complete, login-ready demo dataset and stays idempotent across re-runs', function (): void {
    // Token set + queue faked: the whole seed must stay under withoutDispatching,
    // so a configured token never enqueues a markDone Telegram push.
    config()->set('services.telegram.bot_token', 'test-token');
    Queue::fake();

    $exitCode = $this->artisan('demo:seed')->run();
    expect($exitCode)->toBe(0);

    Queue::assertNotPushed(SendTelegramNotificationJob::class);

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();

    // Core row counts — 35 scripted + RNG fillers @ 65% over ~180d + 1 D-0
    // cold-start run; exact match fails loud on drift.
    $activityIds = Activity::query()->where('user_id', $user->id)->pluck('id');
    $activityCount = $activityIds->count();
    expect($activityCount)->toBe(127)
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

    // At most one unlock equipped per slot: no double-equipped Medali (#53).
    $slots = new EquippedAccessories();
    $equippedSlots = array_map($slots->slotFor(...), $equipped);
    expect($equippedSlots)->toBe(array_unique($equippedSlots));

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

    // Recaps respect the closed-period cap (RecapPeriod): the demo never stages a
    // recap for the still-running current week/month, matching real narration.
    $openWeeklyIds = WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->whereDate('week_ending', '>', RecapPeriod::lastClosedWeekEnding())
        ->pluck('id');
    expect($openWeeklyIds)->not->toBeEmpty(); // the frozen clock leaves a current open week
    expect(Analysis::query()
        ->where('subject_type', WeeklySnapshot::class)
        ->whereIn('subject_id', $openWeeklyIds)
        ->where('analysis_type', AnalysisType::WeeklyRecap)
        ->count())->toBe(0);
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::MonthlyRecap)
        ->where('discriminator', '>', RecapPeriod::lastClosedMonth())
        ->count())->toBe(0);

    // A second bare seed (no wipe) converges to the same row counts.
    $cardCount = RunCard::query()->whereIn('activity_id', $activityIds)->count();
    $snapshotCount = WeeklySnapshot::query()->where('user_id', $user->id)->count();
    $prCount = PersonalRecord::query()->where('user_id', $user->id)->count();

    // Simulate a stale connection (expired + revoked); re-seed must heal it.
    StravaConnection::query()->where('user_id', $user->id)->update([
        'token_expires_at' => Carbon::now()->subDay(),
        'revoked_at' => Carbon::now(),
    ]);

    $this->artisan('demo:seed')->assertSuccessful();

    $connection = StravaConnection::query()->where('user_id', $user->id)->firstOrFail();
    expect(StravaConnection::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($connection->token_expires_at->isFuture())->toBeTrue()
        ->and($connection->revoked_at)->toBeNull();

    $reseededActivityIds = Activity::query()->where('user_id', $user->id)->pluck('id');
    expect(User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->count())->toBe(1)
        ->and($reseededActivityIds)->toHaveCount($activityCount)
        ->and(RunCard::query()->whereIn('activity_id', $reseededActivityIds)->count())->toBe($cardCount)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe($snapshotCount)
        ->and(PersonalRecord::query()->where('user_id', $user->id)->count())->toBe($prCount);
});
