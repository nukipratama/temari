<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Enums\PrCategory;
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
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use App\Services\AI\ServedBy;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Story\Card\CardFacts;
use App\Services\Run\Story\Card\RunForm;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

/**
 * Commits whatever the current test just seeded for real — outside the
 * per-test transaction RefreshDatabase wraps every test in — then reopens
 * that transaction so the running test's own further writes still roll back
 * normally at teardown. This is what lets the fixture below outlive the one
 * test that seeds it.
 */
function commitSharedDemoFixture(): void
{
    DB::connection('mysql')->commit();
    DB::connection('analytics')->commit();
    DB::connection('mysql')->beginTransaction();
    DB::connection('analytics')->beginTransaction();
}

/**
 * Seeds the bare demo dataset: a real `demo:seed` run, committed for good
 * outside any per-test transaction. Every test that needs the bare dataset
 * calls this first; whichever one finds the demo user missing pays the cost
 * (normally the first test below, in file order, but also any test that runs
 * after releaseSharedDemoFixture() wiped the schema), the rest see
 * already-committed rows. releaseSharedDemoFixture(), called by the last
 * test in this file, hands the schema back clean.
 */
function ensureBareDemoSeeded(): void
{
    if (User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->exists()) {
        return;
    }

    // Token set + queue/notifications faked: seeding must never reach *out*, so a
    // configured token cannot turn a seed run into real Telegram or push traffic.
    // Since the unlock sweep went, the seed notifies nobody at all — it writes
    // the demo's inbox rows straight to the table, so any channel here would
    // mean a send path crept back in.
    config()->set('services.telegram.bot_token', 'test-token');
    Queue::fake();
    $notifications = Notification::fake();

    $exitCode = Artisan::call('demo:seed');
    expect($exitCode)->toBe(0);
    expect(channelsUsedBy($notifications))->toBe([]);

    commitSharedDemoFixture();
}

/**
 * Commits the shared fixture connections one last time *without* reopening a
 * transaction, called at the end of the last test in this file. RefreshDatabase's
 * own teardown then finds each connection's PDO not mid-transaction and, per
 * its own beginDatabaseTransaction() callback, flips RefreshDatabaseState::$migrated
 * back to false — which makes the next RefreshDatabase test in this process
 * (whichever file that belongs to) run a fresh migrate:fresh before it does
 * anything else. That's Laravel's own schema-reset path, reused here instead
 * of hand-rolling a second one, so nothing this file committed for real
 * outlives it.
 */
function releaseSharedDemoFixture(): void
{
    DB::connection('mysql')->commit();
    DB::connection('analytics')->commit();
}

/**
 * Backstop for releaseSharedDemoFixture(): PHPUnit always calls afterAll()
 * once, after the last test of this file that actually ran — even when that
 * is not the test below (an earlier test fails before reaching it, or a
 * `--filter`/TIA-narrowed run never selects it at all). Flipping the flag
 * directly needs no live Application, unlike a throwaway-Application
 * `migrate:fresh`: it is the same plain static property RefreshDatabase's own
 * teardown already reads before every test, so whichever RefreshDatabase test
 * this process runs next always re-migrates instead of trusting whatever this
 * file left committed.
 */
afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

it('seeds a complete, login-ready demo dataset and stays idempotent across re-runs', function (): void {
    ensureBareDemoSeeded();

    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();

    // Core row counts — 35 scripted (one moved off today to D-2, which now
    // excludes a filler that used to land there) + RNG fillers @ 65% over
    // ~180d + 1 D-0 cold-start run; exact match fails loud on drift.
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

    // The week-keyed profile voice is backfilled to a done analysis row.
    $profileVoice = Analysis::query()
        ->where('subject_type', AnalysisType::PROFILE_VOICE_SUBJECT_TYPE)
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

    // F7 / #939: plan narration filled rule-based for the active season, and
    // for whichever of the current week's days already have a run credited
    // on them — a day still ahead shows no read at all.
    $creditedThisWeek = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [
            Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
            Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        ])
        ->whereIn('status', [PlannedSessionStatus::Done, PlannedSessionStatus::Partial, PlannedSessionStatus::Overreached])
        ->count();
    expect($creditedThisWeek)->toBeGreaterThan(0)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->where('status', 'done')->count())->toBe($creditedThisWeek)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanSeasonVoice)->where('status', 'done')->count())->toBe(1);

    // F7: trend_read narrated for every live window (just 7d since #967).
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::TREND_READ_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::TrendRead)
        ->where('status', 'done')
        ->count())->toBe(count(AnalysisType::TREND_READ_RANGES));

    // The demo account never reaches Azure — every trend_read row, 7d
    // included, is filled by the rule-based producer.
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::TREND_READ_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::TrendRead)
        ->where('discriminator', '7d')
        ->value('served_by'))->toBe(ServedBy::RuleBased);

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

    // The race goal is 3% faster than the seeded 10K best, rounded to the
    // nearest 15 s, not an independently hardcoded time (#917).
    $km10Best = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('category', PrCategory::Km10)
        ->value('value_sec');
    expect($km10Best)->not->toBeNull();
    $expectedGoalTimeSec = (int) (round($km10Best * 0.97 / 15) * 15);
    expect($race->goal_time_sec)->toBe($expectedGoalTimeSec);

    // #979. The 10K that backs that goal is itself flagged as a race, so the
    // share card's `race` form (bib chassis, chip splits) is reachable on the
    // demo account instead of unconditionally falling through to the other
    // four forms.
    $raceDetail = ActivityDetail::query()
        ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
        ->where('name', '10K race-pace effort')
        ->firstOrFail();
    expect($raceDetail->workout_type)->toBe(1);

    $raceCard = RunCard::query()->where('activity_id', $raceDetail->activity_id)->firstOrFail();
    expect(CardFacts::from($raceCard)->form)->toBe(RunForm::Race);

    $preference = TrainingPreference::query()->where('user_id', $user->id)->firstOrFail();
    expect($preference->run_days)->not->toBeEmpty()
        ->and($preference->long_run_day)->not->toBeNull()
        ->and($preference->sessions_per_week)->toBeGreaterThan(0);

    // Inbox variety: one kind alone leaves the page a single undifferentiated list.
    $inbox = InboxNotification::query()->where('user_id', $user->id)->orderBy('id')->get();
    expect($inbox->pluck('kind')->unique())->toHaveCount(3);

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
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->count())->toBe($creditedThisWeek)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanSeasonVoice)->count())->toBe(1)
        ->and(Analysis::query()
            ->where('subject_type', AnalysisType::TREND_READ_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::TrendRead)
            ->count())->toBe(count(AnalysisType::TREND_READ_RANGES))
        ->and(InboxNotification::query()->where('user_id', $user->id)->count())->toBe($inboxCount);

    // #1165: every blueprint anchors to Carbon::today(), so its identity
    // shifts whenever "today" does. A re-seed on a *later* calendar day must
    // still replace the timeline instead of stacking a second copy of it
    // onto the account — the bug left several runs piled onto most days.
    Carbon::setTestNow('2026-05-20 12:00:00');
    $this->artisan('demo:seed')->assertSuccessful();

    $driftedActivityIds = Activity::query()->where('user_id', $user->id)->pluck('id');
    expect($driftedActivityIds)->toHaveCount($activityCount);

    $runsPerDay = ActivityDetail::query()
        ->whereIn('activity_id', $driftedActivityIds)
        ->get()
        ->groupBy(fn (ActivityDetail $detail) => $detail->start_date_local->toDateString())
        ->map->count();
    expect($runsPerDay->max())->toBe(1, 'A re-seed on a later day must replace the timeline, not stack a second run onto the same date.');
});

it('leaves every analysis done and rule-based-served unless --with-edge-states is passed', function (): void {
    try {
        ensureBareDemoSeeded();

        expect(Analysis::query()->where('status', '!=', AnalysisStatus::Done)->count())
            ->toBe(0, 'The public demo must not render a pending or failed block.');

        expect(Analysis::query()->where('served_by', ServedBy::Llm)->count())
            ->toBe(0, 'The demo seed never calls the LLM, so no demo row may claim it did.')
            ->and(Analysis::query()->where('served_by', ServedBy::RuleBased)->count())
            ->toBeGreaterThan(0);
    } finally {
        releaseSharedDemoFixture();
    }
});
