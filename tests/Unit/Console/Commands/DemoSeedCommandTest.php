<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Enums\NotificationKind;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\RunQuestion;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\StravaConnection;
use App\Models\RaceGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Notifications\Channels\InAppChannel;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use App\Services\Run\Plan\Periodizer;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\NotificationFake;

uses(RefreshDatabase::class);

// Freeze today so blueprint subDays() anchors and ISO-week math are stable.
beforeEach(fn () => Carbon::setTestNow('2026-05-12 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * Every distinct channel a notification resolved to during the run.
 *
 * @return list<string>
 */
function channelsUsedBy(NotificationFake $notifications): array
{
    // sentNotifications() nests notifiable class => key => notification class => records.
    $channels = [];

    foreach ($notifications->sentNotifications() as $byKey) {
        foreach ($byKey as $byNotification) {
            foreach ($byNotification as $records) {
                foreach ($records as $record) {
                    $channels = [...$channels, ...$record['channels']];
                }
            }
        }
    }

    sort($channels);

    return array_values(array_unique($channels));
}

it('seeds a complete, login-ready demo dataset and stays idempotent across re-runs', function (): void {
    // Token set + queue/notifications faked: seeding must never reach *out*, so a
    // configured token cannot turn a seed run into real Telegram or push traffic.
    // It does legitimately record: unlocks granted while materialising runs route
    // to the in-app inbox, which is what makes the public demo's notification
    // centre non-empty (see docs/decisions/demo-notifications-are-inbox-only.md).
    // So the rule is per-channel rather than "nothing sent" — and asserting the
    // exact channel set keeps both halves: an empty set would mean the seed
    // stopped recording, any other entry would mean it reached outside the app.
    config()->set('services.telegram.bot_token', 'test-token');
    Queue::fake();
    $notifications = Notification::fake();

    $exitCode = $this->artisan('demo:seed')->run();
    expect($exitCode)->toBe(0);

    expect(channelsUsedBy($notifications))->toBe([InAppChannel::class]);

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

    // Every defined accessory unlocks. Nothing is equipped any more: W2 swept
    // the wardrobe with the surface that wore it.
    $unlocked = UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all();
    expect($unlocked)->toContain(
        'accessory.medal_first',
        'accessory.medal_gold',
        'accessory.headband_legendary',
        'accessory.headband_epic',
    );

    // The week-keyed Aku voice is backfilled to a done analysis row.
    $profileVoice = Analysis::query()
        ->where('subject_type', AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('discriminator', Carbon::now()->isoFormat('GGGG-[W]WW'))
        ->first();
    expect($profileVoice)->not->toBeNull()
        ->and($profileVoice->status->value)->toBe('done')
        ->and($profileVoice->content)->not->toBeEmpty();

    // Varied maps: more than one distinct resolved location.
    $distinctLocations = ActivityDetail::query()
        ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
        ->where('activities.user_id', $user->id)
        ->whereNotNull('activity_details.location_name')
        ->distinct()
        ->count('activity_details.location_name');
    expect($distinctLocations)->toBeGreaterThan(1);

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

    // F7: a full 12-week planned-session horizon, self-scaled (no active
    // race), with the current week's past days scored/skipped rather than
    // left stuck at Planned — the exact gap this slice closes.
    $plannedSessionCount = PlannedSession::query()->where('user_id', $user->id)->count();
    expect($plannedSessionCount)->toBe(7 * Periodizer::HORIZON_WEEKS)
        ->and(PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', '<', Carbon::today()->toDateString())
            ->where('status', PlannedSessionStatus::Planned)
            ->count())->toBe(0)
        ->and(PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', Carbon::today()->toDateString())
            ->where('status', '!=', PlannedSessionStatus::Planned)
            ->count())->toBe(0);

    // F7: plan narration filled rule-based for the current week (7 days),
    // this week's adaptation, and the active season.
    expect(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->where('status', 'done')->count())->toBe(7)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanWeekVoice)->where('status', 'done')->count())->toBe(1)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanSeasonVoice)->where('status', 'done')->count())->toBe(1);

    // F7: trend_read narrated for all three windows (30d/90d/12mo).
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::TREND_READ_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::TrendRead)
        ->where('status', 'done')
        ->count())->toBe(count(AnalysisType::TREND_READ_RANGES));

    // F7: the inbox is populated (today's post-run summary at minimum), not
    // the empty state R5 flagged.
    $inboxCount = InboxNotification::query()->where('user_id', $user->id)->count();
    expect($inboxCount)->toBeGreaterThanOrEqual(1);

    // PP4 / P30. Every one of these surfaces was invisible on the demo account
    // until this slice, so each assertion stands for a screen a reviewer could
    // not otherwise see.

    // Settings' preferences card runs on TrainingBaseline fallbacks without a
    // row, and its "which one's the long run?" block is gated on run days.
    // PS14. The preference says the demo user trains for a race, and nothing
    // created one: Race rendered its empty state and Profile's goal chip
    // rendered for nobody, on any fresh database.
    $race = RaceGoal::query()->where('user_id', $user->id)->active()->firstOrFail();
    expect($race->distance_m)->toBeGreaterThan(0)
        ->and($race->goal_time_sec)->toBeGreaterThan(0)
        ->and($race->race_date->isFuture())->toBeTrue();

    $preference = TrainingPreference::query()->where('user_id', $user->id)->firstOrFail();
    expect($preference->run_days)->not->toBeEmpty()
        ->and($preference->long_run_day)->not->toBeNull()
        ->and($preference->sessions_per_week)->toBeGreaterThan(0);

    // Inbox variety: P12's unlock rows are the surface PS9 built and could not
    // see, and one kind alone leaves the page a single undifferentiated list.
    $inbox = InboxNotification::query()->where('user_id', $user->id)->orderBy('id')->get();
    expect($inbox->pluck('kind')->unique())->toHaveCount(4)
        ->and($inbox->where('kind', NotificationKind::Unlock))->not->toBeEmpty();

    // InboxController paginates on id while the page buckets on created_at, so
    // rows written out of chronological order drop whole buckets off the first
    // window. Backdating makes that reachable, so it is asserted rather than
    // assumed.
    expect($inbox->pluck('created_at')->map(fn ($at) => $at->timestamp)->all())
        ->toBe($inbox->pluck('created_at')->map(fn ($at) => $at->timestamp)->sort()->values()->all());

    // VitalsCard gates its steepest-grade and flat-pace tiles on a computed
    // max_grade_pct >= 3, which needs a grade_smooth stream the synthesizer
    // did not emit at all. The newest run carries it because that is the one
    // any reviewer lands on first.
    $newestDetail = ActivityDetail::query()
        ->whereIn('activity_id', $activityIds)
        ->orderByDesc('start_date_local')
        ->firstOrFail();
    expect((float) ($newestDetail->streamSummary()['max_grade_pct'] ?? 0))->toBeGreaterThanOrEqual(3.0);

    // AskAboutRun's prior-question list is gated on there being any.
    $runQuestions = RunQuestion::query()->where('activity_id', $newestDetail->activity_id)->get();
    expect($runQuestions)->not->toBeEmpty()
        ->and($runQuestions->pluck('status')->unique()->all())->toBe([AnalysisStatus::Done])
        ->and($runQuestions->pluck('answer')->filter()->count())->toBe($runQuestions->count());

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

    // F7: re-seeding under the same frozen clock converges rather than
    // duplicating rows for the plan/narration/inbox surfaces this slice adds.
    expect(PlannedSession::query()->where('user_id', $user->id)->count())->toBe($plannedSessionCount)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->count())->toBe(7)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanWeekVoice)->count())->toBe(1)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanSeasonVoice)->count())->toBe(1)
        ->and(Analysis::query()
            ->where('subject_type', AnalysisType::TREND_READ_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::TrendRead)
            ->count())->toBe(count(AnalysisType::TREND_READ_RANGES))
        ->and(InboxNotification::query()->where('user_id', $user->id)->count())->toBe($inboxCount);
});
