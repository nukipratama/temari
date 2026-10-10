<?php

declare(strict_types=1);

use App\Enums\Mood;
use App\Models\User;
use App\Events\ActivityIngested;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\Run\ReconcilePlanJob;
use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Support\Facades\Cache;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Jobs\Run\RebuildTrendSnapshotsJob;
use App\Listeners\DispatchPostRunAnalysis;
use App\Models\PlannedSession;
use App\Models\StoryLine;
use App\Services\Run\Story\Temari;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StravaConnection;
use App\Models\WeeklySnapshot;
use App\Notifications\DayClampedNotification;
use App\Services\AI\AnalysisService;
use App\Services\AI\ServedBy;
use App\Actions\AI\StaggerBackfillAction;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationEligibility;
use App\Services\Run\Plan\ComplianceScorer;
use App\Services\Run\Plan\PlanReconciliationDispatch;
use App\Services\Run\Plan\RestClampRecorder;
use App\Services\AI\MaterialFingerprint;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use App\Services\Run\Trend\TrendSnapshotRepairDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    // The default fixture run is dated 2026-05-10; pin the clock next to it so
    // it stays inside the narration age cutoff as wall time moves on. Cases that
    // exercise the cutoff itself set their own clock and config.
    Carbon::setTestNow('2026-05-11 09:00:00');
    $this->listener = app(DispatchPostRunAnalysis::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Seed an already-ingested activity (analyzed_at set + detail) the listener can fan out from. */
function analyzedActivity(string $startDate = '2026-05-10 06:30:00', ?int $userId = null): Activity
{
    $attributes = ['analyzed_at' => Carbon::now()];
    if ($userId !== null) {
        $attributes['user_id'] = $userId;
    }
    $activity = Activity::factory()->create($attributes);
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startDate),
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
    ]);

    return $activity;
}

function fire(Activity $activity): void
{
    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($activity->id));
}

it('never clamps or requests clamp narration after a run today', function (): void {
    Notification::fake();
    $activity = analyzedActivity(Carbon::today()->setTime(7, 0)->toDateTimeString());
    $session = PlannedSession::factory()->for($activity->user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Long,
    ]);
    $context = BriefingContext::forUser(
        $activity->user,
        Carbon::today(),
        app(TrainingLoad::class)->summary($activity->user, Carbon::today()),
    );
    expect($context->ranToday)->toBeTrue()
        ->and($context->readinessCeiling)->toBe('easy_only');

    fire($activity);

    expect($session->fresh()->clamped_km)->toBeNull()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::PlanClampVoice)->exists())->toBeFalse();
    Notification::assertNotSentTo($activity->user, DayClampedNotification::class);
});

it('never resolves the clamp recorder for a post-ingest run today', function (): void {
    $activity = analyzedActivity(Carbon::today()->setTime(7, 0)->toDateTimeString());
    $this->app->bind(RestClampRecorder::class, function (): never {
        throw new LogicException('The post-ingest listener must not resolve the clamp recorder.');
    });

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('requests card flavor for the run card the ingest minted', function (): void {
    $activity = analyzedActivity();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(AnalyzeCardFlavorJob::class);
    expect(Analysis::query()
        ->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)
        ->exists())->toBeTrue();
});

it('re-narrates card flavor on a re-ingest whose run material changed, without minting a second row', function (): void {
    $activity = analyzedActivity();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);
    $row = Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'card pertama', ServedBy::Llm, fingerprint: 'stale-material');

    fire($activity);

    expect(Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->count())->toBe(1)
        ->and($row->fresh()->status)->not->toBe(AnalysisStatus::Done);
});

it('does not re-bill card flavor on a re-ingest of an unchanged run', function (): void {
    $activity = analyzedActivity();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);
    $row = Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'card pertama', ServedBy::Llm, fingerprint: MaterialFingerprint::forActivity($activity->fresh()));

    Bus::fake();
    fire($activity);

    Bus::assertNotDispatched(AnalyzeCardFlavorJob::class);
    expect($row->fresh()->status)->toBe(AnalysisStatus::Done);
});

it('stamps the card flavor row with the run material fingerprint', function (): void {
    $activity = analyzedActivity();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);
    $row = Analysis::factory()->create([
        'subject_type' => RunCard::class,
        'subject_id' => $card->id,
        'analysis_type' => AnalysisType::CardFlavor,
        'discriminator' => null,
    ]);

    $fingerprint = (fn (): ?string => $this->fingerprintFor($row))->call(new AnalyzeCardFlavorJob($row->id));

    expect($fingerprint)->toBe(MaterialFingerprint::forActivity($activity->fresh()));
});

it('delays the invalidating briefing request so a burst of same-day ingests bills one regeneration', function (): void {
    Carbon::setTestNow('2026-05-19 06:00:00');
    $first = analyzedActivity('2026-05-19 05:30:00');
    fire($first);
    Analysis::query()
        ->where('analysis_type', AnalysisType::BriefingMascotVoice->value)
        ->get()
        ->each(fn (Analysis $row) => app(AnalysisService::class)->markDone($row, 'done', ServedBy::Llm));

    Bus::fake();
    fire(analyzedActivity('2026-05-19 05:40:00', $first->user_id));
    fire(analyzedActivity('2026-05-19 05:50:00', $first->user_id));
    fire(analyzedActivity('2026-05-19 05:55:00', $first->user_id));

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
    Bus::assertDispatched(fn (AnalyzeBriefingMascotVoiceJob $job): bool => $job->delay >= 120);
    Carbon::setTestNow();
});

it('dispatches ProfileVoice keyed by the current ISO week and the briefing keyed by today on first ingest', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatched(fn (AnalyzeProfileVoiceJob $job): bool => Analysis::query()->whereKey($job->analysisId)->value('discriminator') === AnalysisType::currentIsoWeek());
    Bus::assertDispatched(fn (AnalyzeBriefingMascotVoiceJob $job): bool => Analysis::query()->whereKey($job->analysisId)->value('discriminator') === '2026-05-19');
    Carbon::setTestNow();
});

it('does not re-bill a Done ProfileVoice row on re-ingest (invalidate:false)', function (): void {
    $activity = analyzedActivity();
    fire($activity);

    $row = Analysis::query()
        ->where('subject_type', AnalysisType::PROFILE_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $activity->user_id)
        ->where('analysis_type', AnalysisType::ProfileVoice)
        ->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'first Temari note', ServedBy::Llm);

    fire($activity);

    expect(Analysis::query()
        ->where('subject_type', AnalysisType::PROFILE_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $activity->user_id)
        ->where('analysis_type', AnalysisType::ProfileVoice)
        ->count())->toBe(1)
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done);
});

it('fans out the activity group once, the briefing, the monthly recap and the reconciliation marker', function (): void {
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);

    $row = Analysis::query()
        ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->where('subject_id', $activity->user_id)
        ->where('analysis_type', AnalysisType::MonthlyRecap)
        ->where('discriminator', '2026-05')
        ->firstOrFail();

    expect($row->status)->toBe(AnalysisStatus::Pending);

    expect($activity->user->fresh()->plan_reconciliation_pending_from->toDateString())
        ->toBe('2026-05-10');
    Bus::assertDispatched(ReconcilePlanJob::class);
});

it('stages the weekly recap Pending without an LLM dispatch (weekly cadence), leaving the recalibration lock free', function (): void {
    $activity = analyzedActivity('2026-05-10 12:00:00');

    fire($activity);

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);

    $snapshot = WeeklySnapshot::query()->where('user_id', $activity->user_id)->firstOrFail();
    $row = Analysis::query()
        ->where('subject_type', WeeklySnapshot::class)
        ->where('subject_id', $snapshot->id)
        ->where('analysis_type', AnalysisType::WeeklyRecap)
        ->firstOrFail();
    expect($row->status)->toBe(AnalysisStatus::Pending);

    expect(WeeklySnapshot::query()->where('user_id', $activity->user_id)->exists())->toBeTrue()
        ->and(Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($activity->user_id)))->toBeNull()
        ->and(Cache::lock(RecalibrateTrainingHistoryJob::overlapLockKey($activity->user_id), 1)->get())->toBeTrue();
});

it('marks a backfilled run week dirty and stages its recap against the week already synced', function (): void {
    $activity = analyzedActivity('2026-04-15 06:30:00');
    $synced = WeeklySnapshot::factory()->for($activity->user)->create(['week_ending' => '2026-04-19']);

    fire($activity);

    expect($activity->user->fresh()->weekly_snapshots_dirty_from->toDateString())->toBe('2026-04-19')
        ->and(WeeklySnapshot::query()->where('user_id', $activity->user_id)->count())->toBe(1)
        ->and(Analysis::query()->forSubject(WeeklySnapshot::class, $synced->id, AnalysisType::WeeklyRecap)->value('status'))
        ->toBe(AnalysisStatus::Pending);
});

it('rebuilds a demo backfilled run inline, since no tick ever rolls the demo forward', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $activity = analyzedActivity('2026-04-15 06:30:00', $demo->id);

    fire($activity);

    expect($demo->fresh()->weekly_snapshots_dirty_from)->toBeNull()
        ->and(WeeklySnapshot::query()->where('user_id', $demo->id)->where('week_ending', '2026-04-19')->exists())->toBeTrue();
});

it('skips its weekly rebuild and marks recalibration dirty while recalibration runs', function (): void {
    $activity = analyzedActivity();
    $recalibration = Cache::lock(RecalibrateTrainingHistoryJob::overlapLockKey($activity->user_id), 150);
    $recalibration->get();

    try {
        fire($activity);
    } finally {
        $recalibration->release();
    }

    expect(WeeklySnapshot::query()->where('user_id', $activity->user_id)->exists())->toBeFalse()
        ->and(Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($activity->user_id)))->toBeTrue()
        ->and(Cache::lock(WeeklyAggregator::lockKey($activity->user_id), 1)->get())->toBeTrue();
    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
});

it('lets the recalibration re-run cover a run ingested while it held its lock', function (): void {
    $activity = analyzedActivity();
    $user = $activity->user;

    app(PlanRecalibrationService::class)->exclusively($user, false, 150, function () use ($activity): array {
        fire($activity);

        return [];
    });

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeFalse();
    $rerun = null;
    Bus::assertDispatched(function (RecalibrateTrainingHistoryJob $job) use ($user, &$rerun): bool {
        $rerun = $job;

        return $job->userId === $user->id;
    });

    $rerun->handle(app(PlanRecalibrationService::class));

    $week = WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', '2026-05-10')->firstOrFail();
    expect($week->runs)->toBe(1)
        ->and($week->distance_km)->toBe(5.0)
        ->and(Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id)))->toBeNull();
});

it('leaves a Done weekly recap untouched on re-ingest (no mid-week invalidation)', function (): void {
    $activity = analyzedActivity('2026-05-10 12:00:00');
    fire($activity);

    $snapshot = WeeklySnapshot::query()->where('user_id', $activity->user_id)->firstOrFail();
    $row = Analysis::query()
        ->where('subject_type', WeeklySnapshot::class)
        ->where('subject_id', $snapshot->id)
        ->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'recap from a reread', ServedBy::Llm);

    fire($activity);

    expect($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->content)->toBe('recap from a reread');
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not stage a monthly recap for the demo user (monthly is real-users-only)', function (): void {
    $demo = User::factory()->demo()->create();
    $activity = analyzedActivity('2026-05-10 06:30:00', $demo->id);

    fire($activity);

    expect(Analysis::query()
        ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->where('subject_id', $demo->id)
        ->exists())->toBeFalse();
});

it('refreshes the daily briefing set on the second run of the day', function (): void {
    Carbon::setTestNow('2026-05-19 06:00:00');
    $first = analyzedActivity('2026-05-19 05:30:00');
    fire($first);

    // The morning's briefing set finishes generating (rows flip to Done).
    Analysis::query()
        ->whereIn('analysis_type', [
            AnalysisType::BriefingMascotVoice->value,
            AnalysisType::BriefingMascotVoice->value,
        ])
        ->get()
        ->each(fn (Analysis $row) => app(AnalysisService::class)->markDone($row, 'done', ServedBy::Llm));

    Bus::fake();
    Carbon::setTestNow('2026-05-19 17:45:00');
    $second = analyzedActivity('2026-05-19 17:30:00', $first->user_id);
    fire($second);

    // A second run today re-narrates the whole daily set so each block reflects
    // both of today's runs, not just the morning one.
    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Carbon::setTestNow();
});

it('does not re-bill the daily set when backfilling a previous-day run', function (): void {
    Carbon::setTestNow('2026-05-19 09:00:00');
    $today = analyzedActivity('2026-05-19 06:00:00');
    fire($today);

    // Today's daily set finishes generating (rows flip to Done).
    Analysis::query()
        ->whereIn('analysis_type', [
            AnalysisType::BriefingMascotVoice->value,
            AnalysisType::BriefingMascotVoice->value,
        ])
        ->get()
        ->each(fn (Analysis $row) => app(AnalysisService::class)->markDone($row, 'done', ServedBy::Llm));

    Bus::fake();
    // Backfilling a run from two days ago must not re-bill today's daily set.
    $backfill = analyzedActivity('2026-05-17 06:00:00', $today->user_id);
    fire($backfill);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Carbon::setTestNow();
});

it('backfill never falls into the filler branch (group rows stay non-Done)', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $backfill = analyzedActivity('2026-05-20 06:00:00');

    fire($backfill);

    // Backfill stages Pending then dispatches; never the create-and-fill (Done)
    // branch that would inject rule-based prose into the connected chain.
    $row = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $backfill->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->firstOrFail();
    expect($row->status)->not->toBe(AnalysisStatus::Done);
    Carbon::setTestNow();
});

it('backfill kickoff dispatches the user earliest Pending group, not the just-ingested run', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    // An older run already staged Pending (e.g. an earlier ingest), still awaiting the chain.
    $older = analyzedActivity('2026-05-10 06:00:00');
    app(AnalysisService::class)->requestActivityGroupDeferred($older);

    Bus::fake();
    // A newer backfilled run is now ingested.
    $newer = analyzedActivity('2026-05-20 06:00:00', $older->user_id);
    fire($newer);

    // The kickoff re-kicks the user's earliest Pending group (the older run).
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $older->id);
    Carbon::setTestNow();
});

it('staggers card_flavor by the same backfill delay as the activity group', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    config()->set('ai.backfill_stagger_seconds', 100);

    // First backfilled ingest reserves the immediate (0-delay) slot for this user.
    $first = analyzedActivity('2026-05-01 06:00:00');
    fire($first);

    Bus::fake();
    // Second backfilled ingest for the same user gets staggered behind the first.
    $activity = analyzedActivity('2026-05-02 06:00:00', $first->user_id);
    RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(fn (AnalyzeCardFlavorJob $job): bool => $job->delay === 100);
    Carbon::setTestNow();
});

it('staggers ProfileVoice by the backfill delay on the ingest that first originates its row', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    config()->set('ai.backfill_stagger_seconds', 100);
    $activity = analyzedActivity('2026-05-01 06:00:00');

    // Reserve the immediate (0-delay) slot ahead of this ingest, so its own
    // dispatch — including the ProfileVoice row it originates — is staggered.
    app(StaggerBackfillAction::class)($activity->user_id);

    fire($activity);

    Bus::assertDispatched(fn (AnalyzeProfileVoiceJob $job): bool => $job->delay === 100);
    Carbon::setTestNow();
});

it('does not grow a recent run\'s stagger delay with the number of rule-based backfill runs ahead of it', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    config()->set('ai.backfill_stagger_seconds', 100);
    $connectedAt = Carbon::parse('2026-06-10 08:00:00');

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => $connectedAt]);

    // Oldest-first drain: several pre-connect runs, well outside the last-7-
    // days window, land first and fill rule-based. None of them narrates to
    // the LLM, so none should reserve a backfill stagger slot.
    foreach (range(0, 4) as $i) {
        fire(analyzedActivity(Carbon::parse('2025-01-01 06:00:00')->addDays($i)->toDateTimeString(), $user->id));
    }

    // The most recent run, inside the last-7-days pre-connect window, lands
    // last with nothing older left to hydrate: it is eligible for real
    // narration and should reserve the immediate (0-delay) slot, regardless
    // of how many rule-based runs preceded it.
    Bus::fake();
    $recent = analyzedActivity('2026-06-05 06:00:00', $user->id);
    RunCard::factory()->create(['activity_id' => $recent->id]);

    fire($recent);

    // No ->delay() call leaves the job's delay null, which PendingDispatch
    // treats as immediate — the same "reserved the 0-delay slot" outcome
    // StaggerBackfillActionTest asserts directly on the action itself.
    Bus::assertDispatched(fn (AnalyzeCardFlavorJob $job): bool => ($job->delay ?? 0) === 0);
    Carbon::setTestNow();
});

it('fills an activity older than the backfill depth cap rule-based (group + card), no real dispatch', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    config()->set('ai.backfill_max_age_days', 365);
    // Well over 365 days before 2026-06-10.
    $activity = analyzedActivity('2025-01-01 06:00:00');
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Bus::assertNotDispatched(AnalyzeCardFlavorJob::class);

    $groupRows = Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $activity->id)->get();
    expect($groupRows)->toHaveCount(2)
        ->and($groupRows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done))->toBeTrue();

    $cardRow = Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail();
    expect($cardRow->status)->toBe(AnalysisStatus::Done);

    Carbon::setTestNow();
});

it('routes an ingest either side of the shipped cutoff differently, against the real config default', function (int $daysAgo, bool $expectRealNarration): void {
    // No config()->set here on purpose: this is the proof that the value
    // config/ai.php actually ships is the one production routes on.
    Carbon::setTestNow('2026-06-10 09:00:00');
    expect(config('ai.backfill_max_age_days'))->toBe(84);

    $activity = analyzedActivity(Carbon::now()->subDays($daysAgo)->toDateTimeString());
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    $cardRow = Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail();

    if ($expectRealNarration) {
        Bus::assertDispatched(AnalyzeCardFlavorJob::class);
        expect($cardRow->status)->not->toBe(AnalysisStatus::Done);
    } else {
        Bus::assertNotDispatched(AnalyzeCardFlavorJob::class);
        Bus::assertNotDispatched(AnalyzeActivityJob::class);
        expect($cardRow->status)->toBe(AnalysisStatus::Done)
            ->and($cardRow->content)->toBeString()->not->toBeEmpty();
    }

    Carbon::setTestNow();
})->with([
    'a day inside the 12-week window still bills the LLM' => [83, true],
    'the cutoff day itself is filled rule-based' => [84, false],
]);

it('steady-state (fresh run) dispatches the activity group immediately', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $fresh = analyzedActivity('2026-06-10 06:00:00');

    fire($fresh);

    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $fresh->id);
    Carbon::setTestNow();
});

it('a live (non-backfill) run joins the chain instead of jumping ahead when an older link is unresolved', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    // An older run already staged Pending (e.g. an in-progress backfill).
    $older = analyzedActivity('2026-05-10 06:00:00');
    app(AnalysisService::class)->requestActivityGroupDeferred($older);

    Bus::fake();
    // A live (fresh, non-backfill) run comes in while that older link is still unresolved.
    $fresh = analyzedActivity('2026-06-10 06:00:00', $older->user_id);
    fire($fresh);

    // Staged (joins the chain), not dispatched directly.
    $freshRow = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $fresh->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->firstOrFail();
    expect($freshRow->status)->toBe(AnalysisStatus::Pending);

    // The chain re-kicks the older, still-earliest link — not the fresh run.
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $older->id);
    Bus::assertNotDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $fresh->id,
    );
    Carbon::setTestNow();
});

/** Seed a fully-narrated (Done) per-run analysis group with a given stored fingerprint. */
function narratedGroup(Activity $activity, ?string $fingerprint): void
{
    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::query()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
            'status' => AnalysisStatus::Done,
            'content' => 'narasi lama',
            'content_fingerprint' => $fingerprint,
            'generated_at' => Carbon::now(),
        ]);
    }
}

function postRunSpeechRow(Activity $activity): Analysis
{
    return Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->firstOrFail();
}

it('re-narrates the latest run when its material data changed since narration', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    narratedGroup($activity, 'stale-fingerprint');

    fire($activity);

    // Invalidated out of Done and re-queued for a fresh narration.
    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id);
    Carbon::setTestNow();
});

it('leaves the latest run Done when the material fingerprint is unchanged (jitter-safe)', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    $current = MaterialFingerprint::forActivity(Activity::with('detail')->findOrFail($activity->id));
    narratedGroup($activity, $current);

    fire($activity);

    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Done)
        ->and(postRunSpeechRow($activity)->content)->toBe('narasi lama');
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('does not force-refresh a pre-feature run with no stored fingerprint', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    narratedGroup($activity, null);

    fire($activity);

    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('does not auto-refresh an older, non-latest run even when its data changed', function (): void {
    Carbon::setTestNow('2026-06-10 12:00:00');
    $older = analyzedActivity('2026-06-10 06:00:00');
    analyzedActivity('2026-06-10 10:00:00', $older->user_id); // the latest run
    narratedGroup($older, 'stale-fingerprint');

    fire($older);

    expect(postRunSpeechRow($older)->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('holds off re-narrating while the run is still in its cooldown window', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    narratedGroup($activity, 'stale-fingerprint');
    postRunSpeechRow($activity)->startCooldown();

    fire($activity);

    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('no-ops when the activity was deleted before the queued listener ran', function (): void {
    $activity = analyzedActivity();
    $id = $activity->id;
    $activity->detail()->delete();
    $activity->delete();

    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($id));

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('skips weekly and monthly staging when the activity has no start_date_local', function (): void {
    $activity = Activity::factory()->create(['analyzed_at' => Carbon::now()]);
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => null,
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
    ]);

    fire($activity);

    expect(Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->exists())->toBeFalse()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::MonthlyRecap)->exists())->toBeFalse();
    // The rest of the fan-out (activity group + daily set) is unaffected by a
    // missing start date, since $isToday null-safes to false rather than erroring.
    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('skips weekly recap staging when rebuildForwardFrom finds no in-window history', function (): void {
    // WeeklyAggregator's own rebuild correctness has its own dedicated suite
    // (WeeklyAggregatorTest); this only checks the listener's own branch —
    // a null return means no history to stage a recap against.
    $activity = analyzedActivity('2026-05-10 12:00:00');
    $weekly = Mockery::mock(WeeklyAggregator::class);
    $weekly->shouldReceive('rebuildForwardFrom')->once()->andReturnNull();
    $listener = new DispatchPostRunAnalysis(
        app(AnalysisService::class),
        $weekly,
        app(StaggerBackfillAction::class),
        app(NarrationEligibility::class),
        app(ComplianceScorer::class),
        app(PlanReconciliationDispatch::class),
        app(TrendSnapshotRepairDispatch::class),
        app(Temari::class),
    );

    $listener->handle(new ActivityIngested($activity->id));

    expect(Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->exists())->toBeFalse();
});

it('fills a pre-connect run rule-based and dispatches no LLM job for it', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-05-20 06:00:00');
    StravaConnection::factory()->for($activity->user)->create(['created_at' => Carbon::parse('2026-06-09 12:00:00')]);
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Bus::assertNotDispatched(AnalyzeCardFlavorJob::class);

    $groupRows = Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $activity->id)->get();
    expect($groupRows)->toHaveCount(2)
        ->and($groupRows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done))->toBeTrue();

    $cardRow = Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail();
    expect($cardRow->status)->toBe(AnalysisStatus::Done)
        ->and($cardRow->content)->toBeString()->not->toBeEmpty();

    Carbon::setTestNow();
});

it('narrates a pre-connect run inside the last 7 days instead of filling it rule-based', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    // 5 days old: historical (predates the connection below) but inside the
    // window NarrateOnReturnJob already applies to pending runs.
    $activity = analyzedActivity('2026-06-05 06:00:00');
    StravaConnection::factory()->for($activity->user)->create(['created_at' => Carbon::parse('2026-06-09 12:00:00')]);
    RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class);

    $groupRows = Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $activity->id)->get();
    expect($groupRows->every(fn (Analysis $row): bool => $row->status !== AnalysisStatus::Done))->toBeTrue();

    Carbon::setTestNow();
});

it('a 60-day backfill narrates only the last 7 days, rule-based on the rest', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $connectedAt = Carbon::parse('2026-06-10 08:00:00');

    $recent = analyzedActivity('2026-06-05 06:00:00');
    StravaConnection::factory()->for($recent->user)->create(['created_at' => $connectedAt]);
    $recentCard = RunCard::factory()->create(['activity_id' => $recent->id]);
    fire($recent);

    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $recent->id);
    expect(Analysis::query()->forSubject(RunCard::class, $recentCard->id, AnalysisType::CardFlavor)->firstOrFail()->status)
        ->not->toBe(AnalysisStatus::Done);

    Bus::fake();
    $old = analyzedActivity('2026-04-12 06:00:00', $recent->user_id);
    $oldCard = RunCard::factory()->create(['activity_id' => $old->id]);
    fire($old);

    Bus::assertNotDispatched(AnalyzeCardFlavorJob::class);
    $oldGroupRows = Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $old->id)->get();
    expect($oldGroupRows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done))->toBeTrue()
        ->and(Analysis::query()->forSubject(RunCard::class, $oldCard->id, AnalysisType::CardFlavor)->firstOrFail()->status)
        ->toBe(AnalysisStatus::Done);

    Carbon::setTestNow();
});

it('a 3-day backfill narrates every imported run', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $connectedAt = Carbon::parse('2026-06-10 08:00:00');

    $activity = analyzedActivity('2026-06-08 06:00:00');
    StravaConnection::factory()->for($activity->user)->create(['created_at' => $connectedAt]);
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class);
    expect(Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail()->status)
        ->not->toBe(AnalysisStatus::Done);

    Carbon::setTestNow();
});

it('still narrates a run logged after the Strava connect', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    StravaConnection::factory()->for($activity->user)->create(['created_at' => Carbon::parse('2026-05-01 12:00:00')]);
    RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class);

    Carbon::setTestNow();
});

function awayFromTheApp(Activity $activity): void
{
    $activity->user->forceFill(['last_seen_at' => Carbon::today()->subDays(8)])->save();
}

it('defers every LLM call for a run synced while the athlete is away from the app', function (): void {
    Notification::fake();
    $activity = analyzedActivity(Carbon::today()->setTime(6, 30)->toDateTimeString());
    awayFromTheApp($activity);
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(RebuildTrendSnapshotsJob::class);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Bus::assertNotDispatched(AnalyzeCardFlavorJob::class);
    Notification::assertNothingSent();

    $groupRows = Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $activity->id)->get();
    expect($groupRows)->toHaveCount(2)
        ->and($groupRows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Pending))->toBeTrue()
        ->and(Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail()->status)
        ->toBe(AnalysisStatus::Pending)
        ->and(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->exists())->toBeFalse()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::ProfileVoice)->exists())->toBeFalse();
});

it('still stages the recaps and scores the day for an athlete away from the app', function (): void {
    $activity = analyzedActivity(Carbon::today()->setTime(6, 30)->toDateTimeString());
    awayFromTheApp($activity);
    $session = PlannedSession::factory()->for($activity->user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Easy,
        'status' => PlannedSessionStatus::Planned,
    ]);

    fire($activity);

    expect($session->fresh()->status->isCredited())->toBeTrue()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->exists())->toBeTrue()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::MonthlyRecap)->exists())->toBeTrue();
});

it('narrates the run of an athlete seen on the edge of the active window exactly as before', function (): void {
    $activity = analyzedActivity(Carbon::today()->setTime(6, 30)->toDateTimeString());
    $activity->user->forceFill(['last_seen_at' => Carbon::today()->subDays(7)])->save();
    RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeProfileVoiceJob::class);
});

it('narrates a recent run right away (the early pass) while its older history is still hydrating', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-05 06:00:00');
    StravaConnection::factory()->for($activity->user)->create(['created_at' => Carbon::parse('2026-06-10 08:00:00')]);
    RunCard::factory()->create(['activity_id' => $activity->id]);
    $older = Activity::factory()->for($activity->user)->summaryOnly()->create();
    ActivityDetail::factory()->for($older)->create(['start_date_local' => Carbon::parse('2025-11-26 06:00:00')]);

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeCardFlavorJob::class);

    Carbon::setTestNow();
});

function briefingRow(int $userId, string $today): ?Analysis
{
    return Analysis::query()
        ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->where('subject_id', $userId)
        ->where('analysis_type', AnalysisType::BriefingMascotVoice)
        ->where('discriminator', $today)
        ->first();
}

function profileVoiceRow(int $userId, string $isoWeek): ?Analysis
{
    return Analysis::query()
        ->where('subject_type', AnalysisType::PROFILE_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $userId)
        ->where('analysis_type', AnalysisType::ProfileVoice)
        ->where('discriminator', $isoWeek)
        ->first();
}

it('narrates the daily briefing right away while a run within past-you\'s reach is still hydrating', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $today = '2026-06-10';
    $activity = analyzedActivity('2026-06-10 06:00:00');
    StravaConnection::factory()->for($activity->user)->create(['created_at' => Carbon::parse('2026-06-10 08:00:00')]);
    // Within past-you's 365-day reach, still awaiting hydration.
    $older = Activity::factory()->for($activity->user)->summaryOnly()->create();
    ActivityDetail::factory()->for($older)->create(['start_date_local' => Carbon::parse('2025-11-26 06:00:00')]);

    fire($activity);

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(briefingRow($activity->user_id, $today)?->status)->toBe(AnalysisStatus::Queued);

    Carbon::setTestNow();
});

it('narrates the profile voice right away while any run of the backlog awaits hydration, even outside past-you\'s reach', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $isoWeek = AnalysisType::currentIsoWeek();
    $activity = analyzedActivity('2026-06-10 06:00:00');
    StravaConnection::factory()->for($activity->user)->create(['created_at' => Carbon::parse('2026-06-10 08:00:00')]);
    // Well outside past-you's 365-day reach, but the profile voice reads the
    // whole history (lifetime stats, the full PR table).
    $ancient = Activity::factory()->for($activity->user)->summaryOnly()->create();
    ActivityDetail::factory()->for($ancient)->create(['start_date_local' => Carbon::parse('2022-01-01 06:00:00')]);

    fire($activity);

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeProfileVoiceJob::class);
    expect(profileVoiceRow($activity->user_id, $isoWeek)?->status)->toBe(AnalysisStatus::Queued);

    Carbon::setTestNow();
});

it('narrates a long-connected athlete\'s briefing and profile voice on schedule despite a stuck old backlog entry (#1032)', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    // Connected well past the hydration grace window (default 48h).
    StravaConnection::factory()->for($activity->user)->create(['created_at' => Carbon::parse('2026-01-01 00:00:00')]);
    // A stuck backlog entry that never finished hydrating.
    $stuck = Activity::factory()->for($activity->user)->summaryOnly()->create();
    ActivityDetail::factory()->for($stuck)->create(['start_date_local' => Carbon::parse('2025-12-01 06:00:00')]);

    fire($activity);

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeProfileVoiceJob::class);

    Carbon::setTestNow();
});

it('refreshes the run mood once its plan day is graded, before narration reads it', function (): void {
    $activity = analyzedActivity();
    PlannedSession::factory()->for($activity->user)->create(['date' => '2026-05-10', 'session_type' => SessionType::Tempo]);
    $activity->detail->update(['stream_summary' => ['time_in_zone_pct' => ['Z2' => 25.0, 'Z3' => 45.0, 'Z4' => 30.0], 'negative_split' => false], 'weather_temp_c' => 24]);
    StoryLine::query()->create(['user_id' => $activity->user_id, 'activity_id' => $activity->id, 'kind' => StoryLine::KIND_POST_RUN, 'mood' => Mood::Easy]);

    fire($activity);

    expect($activity->postRunStoryLine()->first()->mood)->toBe(Mood::Blazing);
});
