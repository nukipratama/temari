<?php

declare(strict_types=1);

use App\Actions\AI\SettleEarlyNarrationAction;
use App\Actions\Run\Story\RecomputeCardClaimsAction;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Enums\PlannedSessionStatus;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, older: Activity, newer: Activity}
 */
function earlyPassUser(): array
{
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);

    $older = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($older)->create(['start_date_local' => Carbon::now()->subDays(2)]);
    $newer = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($newer)->create(['start_date_local' => Carbon::now()->subDay()]);

    foreach ([$older, $newer] as $activity) {
        $card = RunCard::factory()->create(['activity_id' => $activity->id]);
        Analysis::factory()->done()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::PostRunSpeech,
            'discriminator' => null,
            'narrated_early_at' => Carbon::now(),
        ]);
        Analysis::factory()->done()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::RunInsight,
            'discriminator' => null,
            'narrated_early_at' => Carbon::now(),
        ]);
        Analysis::factory()->done()->create([
            'subject_type' => RunCard::class,
            'subject_id' => $card->id,
            'analysis_type' => AnalysisType::CardFlavor,
            'discriminator' => null,
            'narrated_early_at' => Carbon::now(),
        ]);
    }

    Analysis::factory()->done()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => Carbon::today()->toDateString(),
        'narrated_early_at' => Carbon::now(),
    ]);
    Analysis::factory()->done()->create([
        'subject_type' => AnalysisType::ProfileVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::ProfileVoice,
        'discriminator' => AnalysisType::currentIsoWeek(),
        'narrated_early_at' => Carbon::now(),
    ]);

    return ['user' => $user, 'older' => $older, 'newer' => $newer];
}

it('does nothing while the backlog is still hydrating', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);
    $stub = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($stub)->create(['start_date_local' => Carbon::now()->subDay()]);
    $this->mock(PersonalRecords::class)->shouldNotReceive('rebuildForUser');
    $this->mock(RecomputeCardClaimsAction::class)->shouldNotReceive('__invoke');

    app(SettleEarlyNarrationAction::class)($user);
});

it('does nothing for a long-connected athlete, even with a stuck backlog straggler', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subDays(90)]);
    $stuck = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($stuck)->create(['start_date_local' => Carbon::now()->subDays(80)]);
    $this->mock(PersonalRecords::class)->shouldNotReceive('rebuildForUser');
    $this->mock(RecomputeCardClaimsAction::class)->shouldNotReceive('__invoke');

    app(SettleEarlyNarrationAction::class)($user);
});

function backdatableRun(User $user, string $date, int $secPerKm, bool $prSet): Activity
{
    $activity = Activity::factory()->for($user)->create();
    $perKm = [];
    for ($k = 1; $k <= 5; $k++) {
        $perKm[] = ['km' => $k, 'pace' => sprintf('%d:%02d', intdiv($secPerKm, 60), $secPerKm % 60), 'elapsed_sec' => $secPerKm, 'distance_m' => 1000];
    }
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($date),
        'distance' => 5000,
        'stream_summary' => ['per_km' => $perKm],
    ]);
    RunCard::factory()->create(['activity_id' => $activity->id, 'pr_set' => $prSet]);

    return $activity;
}

it('re-judges a later card when a backdated run lands behind it, for a long-connected athlete outside the grace window', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subDays(90)]);
    backdatableRun($user, '2026-01-02 06:00:00', 360, false);
    $day3 = backdatableRun($user, '2026-01-03 06:00:00', 330, true);
    $day1 = backdatableRun($user, '2026-01-01 06:00:00', 300, false);

    app(SettleEarlyNarrationAction::class)($user, $day1->detail->start_date_local);

    expect($day3->runCard()->value('pr_set'))->toBeFalse();
});

it('does not recompute cards on a normal in-order ingest for a long-connected athlete', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subDays(90)]);
    $activity = backdatableRun($user, '2026-01-01 06:00:00', 360, true);

    $this->mock(RecomputeCardClaimsAction::class)->shouldNotReceive('__invoke');

    app(SettleEarlyNarrationAction::class)($user, $activity->detail->start_date_local);
});

it('rebuilds PRs and regenerates a still-claimable row even past the grace window', function (): void {
    // The drain outran the 48h hydration grace window before this settle
    // ever ran, leaving a narrated_early_at row unclaimed — this must still
    // get its one replay rather than being skipped forever.
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subDays(5)]);
    $row = Analysis::factory()->done()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => Carbon::today()->toDateString(),
        'narrated_early_at' => Carbon::now()->subDays(5),
    ]);

    $this->mock(PersonalRecords::class)->shouldReceive('rebuildForUser')->once();
    $this->mock(RecomputeCardClaimsAction::class)->shouldReceive('__invoke')->once()
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect($row->fresh()->narrated_early_at)->toBeNull()
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Queued);
});

it('rebuilds PRs and replays cards once the backlog is empty, even when nothing was ever marked for narration', function (): void {
    // PR detection can be deferred by ActivityPipeline even when the
    // narration for that same run finishes after the drain already landed —
    // so it was never marked `narrated_early_at`.
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);

    $this->mock(PersonalRecords::class)->shouldReceive('rebuildForUser')->once();
    $this->mock(RecomputeCardClaimsAction::class)->shouldReceive('__invoke')->once()
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    Bus::assertNothingDispatched();
});

it('rebuilds PRs, replays cards, and regenerates every early-marked row exactly once', function (): void {
    Bus::fake();
    ['user' => $user, 'older' => $older, 'newer' => $newer] = earlyPassUser();

    $this->mock(PersonalRecords::class)
        ->shouldReceive('rebuildForUser')->once()
        ->with(Mockery::on(fn (User $u): bool => $u->id === $user->id));
    $this->mock(RecomputeCardClaimsAction::class)
        ->shouldReceive('__invoke')->once()
        ->with(Mockery::on(fn (User $u): bool => $u->id === $user->id))
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    expect(Analysis::query()->whereNotNull('narrated_early_at')->count())->toBe(0);

    // Only the earliest activity's group is dispatched directly; its own
    // chain-advance (untested here, covered by AnalyzeActivityJob's own
    // suite) is what would carry $newer's group forward in production.
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $older->id,
    );
    Bus::assertNotDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $newer->id,
    );
    Bus::assertDispatched(AnalyzeCardFlavorJob::class, 2);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeProfileVoiceJob::class);

    expect(Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $older->id)
        ->where('status', AnalysisStatus::Queued)
        ->count())->toBe(2)
        ->and(Analysis::query()
            ->where('subject_type', Activity::class)
            ->where('subject_id', $newer->id)
            ->where('status', AnalysisStatus::Pending)
            ->count())->toBe(2);
});

it('pairs a lone early RunInsight with its own PostRunSpeech row so the group is never left stranded', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()->subDay()]);

    // Only RunInsight was marked early; PostRunSpeech finished a moment later,
    // after the drain had already emptied, so it settled Done with no mark.
    $speech = Analysis::factory()->done()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'narrated_early_at' => null,
    ]);
    $insight = Analysis::factory()->done()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
        'discriminator' => null,
        'narrated_early_at' => Carbon::now(),
    ]);

    $this->mock(PersonalRecords::class)->shouldReceive('rebuildForUser')->once();
    $this->mock(RecomputeCardClaimsAction::class)->shouldReceive('__invoke')->once()
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    // The group's representative row (chain advance + SelfHealer key on it)
    // is reset alongside its sibling, not left Done.
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id,
    );
    expect($speech->fresh()->status)->toBe(AnalysisStatus::Queued)
        ->and($insight->fresh()->status)->toBe(AnalysisStatus::Queued)
        ->and($insight->fresh()->narrated_early_at)->toBeNull();
});

it('requests the deferred Trends read once the drain empties', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-06-17 05:30:00');
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => '2026-06-16 06:30:00']);

    $this->mock(PersonalRecords::class)->shouldReceive('rebuildForUser')->once();
    $this->mock(RecomputeCardClaimsAction::class)->shouldReceive('__invoke')->once()
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    expect(Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::TrendRead)
        ->pluck('discriminator')
        ->all())->toEqualCanonicalizing(AnalysisType::TREND_READ_RANGES);

    Carbon::setTestNow();
});

it('regenerates a thin plan-day voice exactly once when history lands', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);
    $date = Carbon::yesterday();
    PlannedSession::factory()->for($user)->create([
        'date' => $date->toDateString(),
        'status' => PlannedSessionStatus::Done,
    ]);
    $row = Analysis::factory()->done()->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => $date->toDateString(),
        'narrated_early_at' => Carbon::now(),
    ]);

    $this->mock(PersonalRecords::class)->shouldReceive('rebuildForUser')->once();
    $this->mock(RecomputeCardClaimsAction::class)->shouldReceive('__invoke')->once()
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    app(SettleEarlyNarrationAction::class)($user);

    Bus::assertDispatched(AnalyzePlanDayVoiceJob::class);
    expect($row->fresh()->narrated_early_at)->toBeNull()
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Queued);
});

it('leaves a thin plan-day voice Done, not stranded Pending, when nothing about the day actually changed', function (): void {
    // requestDayVoiceIfChanged() has no SelfHealer recovery family, so if its
    // own fingerprint check ever decides nothing changed, the row must stay
    // exactly as it was rather than being pre-flipped to a Pending nothing
    // will ever fill.
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);
    $row = Analysis::factory()->done()->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => Carbon::yesterday()->toDateString(),
        'narrated_early_at' => Carbon::now(),
    ]);

    $this->mock(PersonalRecords::class)->shouldReceive('rebuildForUser')->once();
    $this->mock(RecomputeCardClaimsAction::class)->shouldReceive('__invoke')->once()
        ->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    // No PlannedSession exists for that date at all, so requestDayVoiceIfChanged() no-ops.
    app(SettleEarlyNarrationAction::class)($user);

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    expect($row->fresh()->narrated_early_at)->toBeNull()
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->content)->not->toBeNull();
});

it('bills nothing more on a second completion signal', function (): void {
    Bus::fake();
    ['user' => $user] = earlyPassUser();

    $personalRecords = $this->mock(PersonalRecords::class);
    $personalRecords->shouldReceive('rebuildForUser')->twice();
    $recomputeCardClaims = $this->mock(RecomputeCardClaimsAction::class);
    $recomputeCardClaims->shouldReceive('__invoke')->twice()->andReturn(['cleared' => [], 'earned' => [], 'moods' => 0]);

    $action = app(SettleEarlyNarrationAction::class);
    $action($user->fresh());
    Bus::fake();
    $action($user->fresh());

    // The PR rebuild and card recompute are idempotent and re-run on every
    // completion signal (a racing ingest, an unrelated sweep) — the row-level
    // claim is what stops a second bill, not a call skipped outright.
    Bus::assertNothingDispatched();
});
