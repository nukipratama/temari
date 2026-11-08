<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisRowState;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\CeilingOverride;
use App\Services\Ops\MaintainerAlerter;
use App\Services\AI\NarrationGate;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\ServedBy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

function analysisService(): AnalysisService
{
    return app(AnalysisService::class);
}

it('requestDeferred creates a Pending row, never dispatches, and leaves it untouched once Done', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisService()->requestDeferred(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->queued_at)->toBeNull();

    app(AnalysisRowState::class)->markDone($row, 'last week recap', ServedBy::Llm);

    $again = analysisService()->requestDeferred(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($again->id)->toBe($row->id)
        ->and($again->status)->toBe(AnalysisStatus::Done)
        ->and($again->content)->toBe('last week recap');
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('requestActivityGroupRuleBased fills all rows Done without dispatching', function (): void {
    $activity = Activity::factory()->create();

    analysisService()->requestActivityGroupRuleBased($activity);

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => is_string($row->content) && $row->content !== ''))->toBeTrue()
        ->and($rows->pluck('analysis_type')->all())->toEqualCanonicalizing([
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsight,
        ]);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('requestActivityGroupRuleBased never overwrites an already-Done row with filler (e.g. real narration that aged past the cap)', function (): void {
    $activity = Activity::factory()->create();
    $realRow = Analysis::factory()->done('original narration, already billed')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->requestActivityGroupRuleBased($activity);

    expect(nonNull($realRow->fresh())->content)->toBe('original narration, already billed');
});

it('requestActivityGroup creates its rows, dispatches one AnalyzeActivityJob, and collapses a repeat request to one NULL-discriminator row per type', function (): void {
    $activity = Activity::factory()->create();

    analysisService()->requestActivityGroup($activity);

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('analysis_type')->all())->toEqualCanonicalizing([
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsight,
        ]);
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id);
    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);

    analysisService()->requestActivityGroup($activity);

    expect(Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->whereNull('discriminator')
        ->count())->toBe(2);
});

it('request() with any activity-group type routes to AnalyzeActivityJob (group)', function (): void {
    $activity = Activity::factory()->create();

    analysisService()->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::RunInsight,
    );

    expect(Analysis::query()->where('subject_id', $activity->id)->count())->toBe(2);
    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('activity group debounces — sibling-type requests dispatch only one AnalyzeActivityJob', function (): void {
    $activity = Activity::factory()->create();

    analysisService()->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::PostRunSpeech,
    );
    analysisService()->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::RunInsight,
    );

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    expect(Analysis::query()->where('subject_id', $activity->id)->pluck('generation_token')->unique())
        ->toHaveCount(1)
        ->and(nonNull(Analysis::query()->where('subject_id', $activity->id)->first())->generation_token)->not->toBeNull();
});

it('requestProfileVoice creates the profile-voice row and dispatches one AnalyzeProfileVoiceJob', function (): void {
    $user = User::factory()->create();

    $row = analysisService()->requestProfileVoice($user, '2026-W20');

    expect($row->subject_type)->toBe(AnalysisType::ProfileVoice->subjectType())
        ->and($row->subject_id)->toBe($user->id)
        ->and($row->analysis_type)->toBe(AnalysisType::ProfileVoice)
        ->and($row->discriminator)->toBe('2026-W20');

    Bus::assertDispatched(fn (AnalyzeProfileVoiceJob $job): bool => $job->analysisId === $row->id);
});

it('withoutDispatching suppresses dispatch but still creates Pending rows', function (): void {
    $activity = Activity::factory()->create();

    analysisService()->withoutDispatching(function () use ($activity): void {
        analysisService()->requestActivityGroup($activity);
    });

    expect(Analysis::query()->where('subject_id', $activity->id)->count())->toBe(2)
        ->and(nonNull(Analysis::query()->where('subject_id', $activity->id)->first())->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('withoutDispatching restores prior suppression state on exit', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    analysisService()->withoutDispatching(function () use ($snap): void {
        analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    });
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);

    // After exit, dispatching works again.
    $snap2 = WeeklySnapshot::factory()->create();
    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap2->id,
        type: AnalysisType::WeeklyRecap,
    );
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('pushes one maintainer alert naming the spend, the ceiling and the athletes degraded', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->app->forgetInstance(AnalysisService::class);
    $this->app->forgetInstance(NarrationGate::class);
    $service = app(AnalysisService::class);

    $snap = WeeklySnapshot::factory()->create();
    breachTheTotalCeilingWith($snap->user_id);

    // Memoized for the scope, so a second gated request in the same request
    // does not push again even before the alerter's own cooldown applies.
    $alerter->shouldReceive('totalCeilingReached')->once()->with(6.0, 5.0, 1);

    foreach ([1, 2] as $ignored) {
        $service->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    }
});

it('pushes a maintainer alert when an athlete passes their own slice', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->app->forgetInstance(AnalysisService::class);
    $this->app->forgetInstance(NarrationGate::class);
    $service = app(AnalysisService::class);

    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);

    $alerter->shouldReceive('totalCeilingApproaching')->zeroOrMoreTimes();
    $alerter->shouldReceive('userCeilingReached')->once()->with($snap->user_id, 2.5, 1.0);

    $service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );
});

// The alerter owns the 80% threshold and the dedupe; the ceiling check only
// hands it today's spend while there is still headroom.
it('offers today\'s spend to the early-warning alert while under the app-wide ceiling', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->app->forgetInstance(AnalysisService::class);
    $this->app->forgetInstance(NarrationGate::class);
    $service = app(AnalysisService::class);

    $snap = WeeklySnapshot::factory()->create();
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.daily_cost_ceiling_total' => 5.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 4.00, 'output_per_1m' => 10.00]]]);
    spend($snap->user_id, 1_000_000);

    $alerter->shouldReceive('totalCeilingApproaching')->once()->with(4.0, 5.0);

    $service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );
});

it('accepts a Model instance as the subject', function (): void {
    $detail = ActivityDetail::factory()->for(Activity::factory())->create();

    analysisService()->request(
        subjectOrType: $detail->activity,
        subjectId: $detail->activity_id,
        type: AnalysisType::RunInsight,
    );

    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('requestBriefing creates the suggestion row, dispatches one AnalyzeBriefingMascotVoiceJob, and mints no duplicate when re-requested', function (): void {
    $user = User::factory()->create();

    analysisService()->requestBriefing($user, '2026-05-18');

    // Mascot voice and featured-card voice are dispatched by their own callers.
    expect(Analysis::query()->where('subject_id', $user->id)->where('discriminator', '2026-05-18')->count())->toBe(1);

    $row = Analysis::query()
        ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::BriefingMascotVoice)
        ->where('discriminator', '2026-05-18')
        ->firstOrFail();

    Bus::assertDispatched(fn (AnalyzeBriefingMascotVoiceJob $job): bool => $job->analysisId === $row->id);

    analysisService()->requestBriefing($user, '2026-05-18');

    expect(Analysis::query()
        ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('discriminator', '2026-05-18')
        ->count())->toBe(1);
});

it('rejects a duplicate (subject_type, subject_id, analysis_type, discriminator) at the DB level', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $attributes = [
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => '2026-W20',
    ];
    Analysis::factory()->create($attributes);

    expect(fn (): Analysis => Analysis::factory()->create($attributes))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate row with a NULL discriminator at the DB level', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $attributes = [
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ];
    Analysis::factory()->create($attributes);

    expect(fn (): Analysis => Analysis::factory()->create($attributes))
        ->toThrow(QueryException::class);
});

it('runs the daily cost aggregate once per scope no matter how many rows one athlete dispatches', function (): void {
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.daily_cost_ceiling_per_user' => 50.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    $user = User::factory()->create();
    $snaps = WeeklySnapshot::factory()->for($user)->count(4)->create();

    $aggregates = 0;
    DB::listen(function (QueryExecuted $query) use (&$aggregates): void {
        if (str_contains($query->sql, 'ai_token_usages')) {
            $aggregates++;
        }
    });

    foreach ($snaps as $snap) {
        analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    }

    // The app-wide total once and this athlete's slice once, not once per row.
    expect($aggregates)->toBe(2);
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 4);
});

it('reads each athlete\'s slice once, so a scope touching several does not re-read one of them', function (): void {
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    // Each snapshot brings its own athlete, as a command looping them would.
    $snaps = WeeklySnapshot::factory()->count(4)->create();

    $aggregates = 0;
    DB::listen(function (QueryExecuted $query) use (&$aggregates): void {
        if (str_contains($query->sql, 'ai_token_usages')) {
            $aggregates++;
        }
    });

    foreach ($snaps as $snap) {
        analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    }

    // Four slices plus the one app-wide total the whole scope shares.
    expect($aggregates)->toBe(5);
});

it('re-reads the ceiling in a fresh scope, so a memo never outlives its request or job', function (): void {
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    $aggregates = 0;
    DB::listen(function (QueryExecuted $query) use (&$aggregates): void {
        if (str_contains($query->sql, 'ai_token_usages')) {
            $aggregates++;
        }
    });

    $first = WeeklySnapshot::factory()->create();
    app(AnalysisService::class)->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $first->id,
        type: AnalysisType::WeeklyRecap,
    );

    // What the queue worker does between jobs and Octane does between requests.
    $this->app->forgetScopedInstances();

    $second = WeeklySnapshot::factory()->create();
    app(AnalysisService::class)->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $second->id,
        type: AnalysisType::WeeklyRecap,
    );

    // Two scopes, each reading the app-wide total and its athlete's slice.
    expect($aggregates)->toBe(4);
});

it('honours a ceiling that is already breached when the scope starts', function (): void {
    $snaps = WeeklySnapshot::factory()->count(3)->create();
    foreach ($snaps as $snap) {
        breachTheCeilingFor($snap->user_id);
        $row = analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
        expect($row->status)->toBe(AnalysisStatus::Done);
    }

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
    expect(analysisService()->generationPaused(nonNull($snaps->last())->user_id))->toBeTrue();
});

it('keeps withoutDispatching suppressing after the memo is already warm', function (): void {
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);

    $warm = WeeklySnapshot::factory()->create();
    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $warm->id,
        type: AnalysisType::WeeklyRecap,
    );
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);

    $suppressed = WeeklySnapshot::factory()->create();
    analysisService()->withoutDispatching(function () use ($suppressed): void {
        $row = analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $suppressed->id,
            type: AnalysisType::WeeklyRecap,
        );
        expect($row->status)->toBe(AnalysisStatus::Pending);
        expect(analysisService()->generationPaused())->toBeTrue();
    });

    // Suppression lifts cleanly: the warm memo is still the real answer.
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);
    expect(analysisService()->generationPaused())->toBeFalse();

    $after = WeeklySnapshot::factory()->create();
    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $after->id,
        type: AnalysisType::WeeklyRecap,
    );
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 2);
});

// ── replay: its own app-wide cap, never the athlete's slice ───────────

it('spends a replay against the replay cap rather than the athlete slice', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);
    config(['azure_openai.replay_daily_cap' => 100.0]);
    app(NarrationOrigin::class)->set(AnalysisOrigin::Replay);

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('refuses a replay once today replay spend has reached its cap', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.daily_cost_ceiling_total' => 100.0]);
    config(['azure_openai.replay_daily_cap' => 0.50]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 0.60, 'output_per_1m' => 10.00]]]);
    spend($snap->user_id, 1_000_000, AnalysisOrigin::Replay);
    app(NarrationOrigin::class)->set(AnalysisOrigin::Replay);

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->served_by)->toBe(ServedBy::RuleBased);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

// The two ceilings degrade only *past* their figure; the replay cap refuses
// *at* it, since a replay is discretionary. Locked here so the asymmetry can't
// be tidied away as an inconsistency.
it('refuses a replay whose spend lands exactly on the cap', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.daily_cost_ceiling_total' => 100.0]);
    config(['azure_openai.replay_daily_cap' => 0.50]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 0.50, 'output_per_1m' => 10.00]]]);
    spend($snap->user_id, 1_000_000, AnalysisOrigin::Replay);
    app(NarrationOrigin::class)->set(AnalysisOrigin::Replay);

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('still stops a replay at the app-wide total', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheTotalCeilingWith($snap->user_id);
    config(['azure_openai.replay_daily_cap' => 100.0]);
    app(NarrationOrigin::class)->set(AnalysisOrigin::Replay);

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

// ── today-only per-athlete ceiling override ───────────────────────────

it('reads a today-only override in place of the configured athlete ceiling', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);
    app(CeilingOverride::class)->set($snap->user_id, 10.0);

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('degrades again once the override is cleared', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);
    app(CeilingOverride::class)->set($snap->user_id, 10.0);
    app(CeilingOverride::class)->clear($snap->user_id);

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('rebriefs after a plan edit only once the briefing\'s Reread cooldown has run out', function (bool $cooling): void {
    $user = User::factory()->create();
    $briefing = Analysis::factory()->done('easy run, 2.6 km.')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);
    if ($cooling) {
        $briefing->startCooldown();
    }

    analysisService()->rebriefAfterPlanEdit($user, '2026-05-18');

    if ($cooling) {
        Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
        expect(nonNull($briefing->fresh())->only(['status', 'content']))->toBe(['status' => AnalysisStatus::Done, 'content' => 'easy run, 2.6 km.']);
    } else {
        Bus::assertDispatched(fn (AnalyzeBriefingMascotVoiceJob $job): bool => $job->analysisId === $briefing->id
            && $job->delay === AnalysisService::PLAN_EDIT_DELAY_SECONDS);
    }
})->with(['cooled' => [false], 'cooling' => [true]]);

it('rebriefs after a plan edit inside 15 minutes of a system narration of the briefing, but not of a user Reread', function (AnalysisOrigin $narratedBy, bool $rebriefs): void {
    $user = User::factory()->create();
    $briefing = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);
    app(NarrationOrigin::class)->set($narratedBy);
    app(AnalysisRowState::class)->markDone($briefing, 'easy run, 2.6 km.', ServedBy::Llm);

    app(NarrationOrigin::class)->set(AnalysisOrigin::User);
    analysisService()->rebriefAfterPlanEdit($user, '2026-05-18');

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, $rebriefs ? 1 : 0);
})->with([
    'the 00:01 briefing' => [AnalysisOrigin::Scheduled, true],
    'a user Reread' => [AnalysisOrigin::User, false],
]);

it('rebriefs after a plan edit when no briefing was narrated yet', function (): void {
    $user = User::factory()->create();

    analysisService()->rebriefAfterPlanEdit($user, '2026-05-18');

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});
