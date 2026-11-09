<?php

declare(strict_types=1);

use App\Services\AI\AzureConfigCircuitBreaker;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisRowState;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\LlmCostCalculator;
use App\Services\AI\NarrationGate;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\AI\ServedBy;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use App\Services\AI\AnalysisDispatcher;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

function dispatchAnalysisRow(
    string $subjectType,
    int $subjectId,
    AnalysisType $type,
    ?string $discriminator = null,
    bool $invalidate = false,
    ?int $delaySeconds = null,
): Analysis {
    return app(AnalysisDispatcher::class)->dispatchRow($subjectType, $subjectId, $type, $discriminator, $invalidate, $delaySeconds);
}

function dispatchActivityGroup(Activity $activity, bool $invalidate = false): void
{
    app(AnalysisDispatcher::class)->dispatchGroup(AnalyzeActivityJob::class, $activity->id, null, $invalidate, null);
}

it('creates a pending row and queues a row job stamped unattributed on an undeclared first request', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->queued_at)->not->toBeNull();

    Bus::assertDispatched(fn (AnalyzeWeeklyRecapJob $job): bool => $job->analysisId === $row->id);
    Bus::assertDispatched(fn (AnalyzeWeeklyRecapJob $job): bool => $job->origin === AnalysisOrigin::Unknown);
});

it('stamps the dispatching entry point origin onto the job, so spend attributes to its trigger', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertDispatched(fn (AnalyzeWeeklyRecapJob $job): bool => $job->origin === AnalysisOrigin::Scheduled);
});

it('skips dispatch when status is already done (idempotent)', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('cached recap')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $result = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($result->status)->toBe(AnalysisStatus::Done)
        ->and($result->content)->toBe('cached recap');
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('invalidate=true flips a done row back to queued and re-dispatches', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('old recap')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        invalidate: true,
    );

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
    expect(nonNull(Analysis::query()->first())->status)->toBe(AnalysisStatus::Queued);
});

it('resets attempts to 0 on a user-initiated invalidation (row + group paths)', function (): void {
    app(NarrationOrigin::class)->set(AnalysisOrigin::User);

    // Row path (WeeklyRecap is non-grouped).
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('old')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'attempts' => 3,
    ]);

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        invalidate: true,
    );

    expect(nonNull(Analysis::query()->first())->attempts)->toBe(0);

    // Group path (PostRunSpeech is grouped under AnalyzeActivityJob).
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create();
    Analysis::factory()->done('old speech')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'attempts' => 2,
    ]);

    dispatchActivityGroup($activity, invalidate: true);

    $speechRow = nonNull(Analysis::query()
        ->where('subject_id', $activity->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->first());
    expect($speechRow->attempts)->toBe(0);
});

it('keeps attempts on a system invalidation, so the self-heal budget is per row (row + group paths)', function (): void {
    app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('old')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'attempts' => 3,
    ]);

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        invalidate: true,
    );

    expect(nonNull(Analysis::query()->first())->attempts)->toBe(3);

    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create();
    Analysis::factory()->done('old speech')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
        'attempts' => 2,
    ]);

    dispatchActivityGroup($activity, invalidate: true);

    $speechRow = nonNull(Analysis::query()
        ->where('subject_id', $activity->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->first());
    expect($speechRow->attempts)->toBe(2)
        ->and($speechRow->status)->toBe(AnalysisStatus::Queued);
});

it('enqueues one job when two dispatchers interleave over the same pending row', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    // Run a second, complete dispatch inside the first one's read of the row, so
    // both dispatchers hold a row they read as Pending — the interleaving that a
    // sequential double-call cannot reproduce, because the second call would
    // otherwise re-read the row as Queued.
    $interleaved = false;
    Analysis::retrieved(function () use ($snap, &$interleaved): void {
        if ($interleaved) {
            return;
        }
        $interleaved = true;

        dispatchAnalysisRow(
            subjectType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    });

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($interleaved)->toBeTrue();
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);

    $row = nonNull(Analysis::query()->first());
    expect($row->status)->toBe(AnalysisStatus::Queued);
});

it('claims a row for dispatch only from pending or failed', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    foreach ([AnalysisStatus::Queued, AnalysisStatus::Processing, AnalysisStatus::Done] as $status) {
        Analysis::query()->delete();
        Analysis::factory()->create([
            'subject_type' => WeeklySnapshot::class,
            'subject_id' => $snap->id,
            'analysis_type' => AnalysisType::WeeklyRecap,
            'discriminator' => null,
            'status' => $status,
        ]);

        dispatchAnalysisRow(
            subjectType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );

        expect(nonNull(Analysis::query()->first())->status)->toBe($status);
    }

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('re-dispatches when status is failed', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->failed('previous error')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
    $row = nonNull(Analysis::query()->first());
    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->error)->toBeNull();
});

it('does not re-dispatch an activity group whose speech is already in flight', function (bool $invalidate): void {
    $activity = Activity::factory()->create();
    $rows = app(AnalysisRowState::class)->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );
    $token = 'active-generation';
    nonNull($rows->first())->update([
        'status' => AnalysisStatus::Queued,
        'generation_token' => $token,
    ]);
    Bus::dispatch(new AnalyzeActivityJob($activity->id, null, $token));

    dispatchActivityGroup($activity, $invalidate);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    expect(nonNull(nonNull($rows->first())->fresh())->generation_token)->toBe($token);
})->with([
    'a resume' => [false],
    'a reread' => [true],
]);

it('holds an automatic profile voice Pending while the athlete is behind on streak settlement', function (): void {
    Carbon::setTestNow('2026-05-18 00:21:00');
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $held = dispatchAnalysisRow(AnalysisType::ProfileVoice->subjectType(), $user->id, AnalysisType::ProfileVoice, '2026-W21');

    expect($held->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNotDispatched(AnalyzeProfileVoiceJob::class);

    $user->forceFill(['streak_settled_through' => '2026-05-17'])->saveQuietly();

    dispatchAnalysisRow(AnalysisType::ProfileVoice->subjectType(), $user->id, AnalysisType::ProfileVoice, '2026-W21');

    Bus::assertDispatched(fn (AnalyzeProfileVoiceJob $job): bool => $job->analysisId === $held->id);
    Carbon::setTestNow();
});

it('holds the profile voice of a dirty athlete too', function (): void {
    Carbon::setTestNow('2026-05-20 10:00:00');
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    $user->forceFill([
        'streak_settled_through' => '2026-05-17',
        'streak_settlement_dirty_from' => '2026-05-10',
    ])->saveQuietly();

    dispatchAnalysisRow(AnalysisType::ProfileVoice->subjectType(), $user->id, AnalysisType::ProfileVoice, '2026-W21');

    Bus::assertNotDispatched(AnalyzeProfileVoiceJob::class);
    Carbon::setTestNow();
});

it('lets the athlete\'s own Reread narrate the profile voice while settlement is behind', function (): void {
    Carbon::setTestNow('2026-05-18 09:00:00');
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $row = dispatchAnalysisRow(AnalysisType::ProfileVoice->subjectType(), $user->id, AnalysisType::ProfileVoice, '2026-W21', invalidate: true);

    Bus::assertDispatched(fn (AnalyzeProfileVoiceJob $job): bool => $job->analysisId === $row->id);
    Carbon::setTestNow();
});

it('does not dispatch when ai.auto_dispatch config is false', function (): void {
    config(['ai.auto_dispatch' => false]);
    $snap = WeeklySnapshot::factory()->create();

    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('serves rule-based content, marked as a capped rule-based fill, instead of dispatching once the daily ceiling is exceeded', function (): void {
    $snap = WeeklySnapshot::factory()->create(['runs' => 3, 'distance_km' => 21.0]);
    breachTheCeilingFor($snap->user_id);

    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->content)->toBe(app(RuleBasedNarrationFiller::class)->fillFor($row))
        ->and($row->content)->toContain('21.0 km')
        ->and(nonNull($row->fresh())->served_by)->toBe(ServedBy::RuleBased)
        ->and(nonNull($row->fresh())->rule_based_reason)->toBe(AnalysisOrigin::Capped);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('leaves the row Pending when the kill switch, not the budget, stopped generation', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);

    $snap = WeeklySnapshot::factory()->create();
    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull()
        ->and(app(NarrationGate::class)->costCeilingDegraded())->toBeFalse();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not dispatch, and leaves the row Pending rather than degraded, when Azure is unconfigured', function (): void {
    config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']);

    $snap = WeeklySnapshot::factory()->create();
    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull()
        ->and(app(NarrationGate::class)->costCeilingDegraded())->toBeFalse();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('degrades only the athlete who spent, and leaves everyone else billing normally', function (): void {
    $heavy = WeeklySnapshot::factory()->create();
    $bystander = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($heavy->user_id);

    $heavyRow = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $heavy->id,
        type: AnalysisType::WeeklyRecap,
    );
    $bystanderRow = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $bystander->id,
        type: AnalysisType::WeeklyRecap,
    );

    // The whole reason the ceiling is per athlete: a shared pool let the
    // heaviest athlete of the day silently degrade everyone else's narration.
    expect($heavyRow->status)->toBe(AnalysisStatus::Done)
        ->and($bystanderRow->status)->toBe(AnalysisStatus::Queued)
        ->and(app(NarrationGate::class)->costCeilingDegraded($heavy->user_id))->toBeTrue()
        ->and(app(NarrationGate::class)->costCeilingDegraded($bystander->user_id))->toBeFalse();
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);
});

it('leaves the row Pending when a breached budget sits behind a tripped config breaker', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);
    config(['azure_openai.uri' => 'https://x.openai.azure.com/x', 'azure_openai.api_key' => 'wrong-key']);
    $breaker = app(AzureConfigCircuitBreaker::class);
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }

    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and(app(NarrationGate::class)->costCeilingDegraded())->toBeFalse();
});

it('degrades every row of a group, and never overwrites one already billed for', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => 8000]);
    Analysis::factory()->done('original narration, already billed')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
        'discriminator' => null,
    ]);
    breachTheCeilingFor($activity->user_id);

    dispatchActivityGroup($activity);

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows->pluck('status')->unique()->all())->toBe([AnalysisStatus::Done])
        ->and(nonNull($rows->firstWhere('analysis_type', AnalysisType::RunInsight))->content)
        ->toBe('original narration, already billed')
        ->and(nonNull($rows->firstWhere('analysis_type', AnalysisType::PostRunSpeech))->content)
        ->toBe(app(RuleBasedNarrationFiller::class)->fillFor(
            nonNull($rows->firstWhere('analysis_type', AnalysisType::PostRunSpeech)),
        ));
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('leaves a Failed row Failed past the ceiling while its Pending sibling degrades', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->create(['activity_id' => $activity->id, 'distance' => 8000]);
    Analysis::factory()->failed('narator meledak')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
        'discriminator' => null,
    ]);
    breachTheCeilingFor($activity->user_id);

    dispatchActivityGroup($activity);

    $rows = Analysis::query()
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $row): string => $row->analysis_type->value);

    // The broken block keeps its fault (and its dead-letter visibility); the
    // untouched sibling still gets content, so this is an exclusion, not a
    // whole-group opt-out.
    expect(nonNull($rows['run_insight'])->status)->toBe(AnalysisStatus::Failed)
        ->and(nonNull($rows['run_insight'])->error)->toBe('narator meledak')
        ->and(nonNull($rows['run_insight'])->content)->toBeNull()
        ->and(nonNull($rows['post_run_speech'])->status)->toBe(AnalysisStatus::Done)
        ->and(nonNull($rows['post_run_speech'])->content)->toBe(
            app(RuleBasedNarrationFiller::class)->fillFor(nonNull($rows['post_run_speech'])),
        );
    expect(app(CostCeilingLedger::class)->today()['degradedFills'])->toBe(1);
});

it('records the trip time and the degraded-fill count for /devtools/narration', function (): void {
    $this->freezeTime();

    foreach (WeeklySnapshot::factory()->count(2)->create() as $snap) {
        breachTheCeilingFor($snap->user_id);
        dispatchAnalysisRow(
            subjectType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    }

    $today = app(CostCeilingLedger::class)->today();

    expect($today['trippedAt'])->toBe(Carbon::now()->toIso8601String())
        ->and($today['degradedFills'])->toBe(2);
});

it('keeps dispatching while app-wide spend is still under the total ceiling', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheTotalCeilingWith($snap->user_id);
    config(['azure_openai.daily_cost_ceiling_total' => 10.0]);

    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and(app(NarrationGate::class)->costCeilingDegraded($snap->user_id))->toBeFalse();
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('degrades an athlete who has spent nothing once the app-wide ceiling is passed', function (): void {
    $heavy = WeeklySnapshot::factory()->create();
    $bystander = WeeklySnapshot::factory()->create();
    breachTheTotalCeilingWith($heavy->user_id);

    $bystanderRow = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $bystander->id,
        type: AnalysisType::WeeklyRecap,
    );

    // Their own slice is untouched: only the app-wide stop above it degrades them.
    expect(app(LlmCostCalculator::class)->dailyCost($bystander->user_id))->toBe(0.0)
        ->and($bystanderRow->status)->toBe(AnalysisStatus::Done)
        ->and($bystanderRow->content)->toBe(
            app(RuleBasedNarrationFiller::class)->fillFor($bystanderRow),
        )
        ->and(app(NarrationGate::class)->costCeilingDegraded($bystander->user_id))->toBeTrue();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('leaves a Failed row Failed once the app-wide ceiling is passed', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = Analysis::factory()->failed('narrator exploded')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);
    breachTheTotalCeilingWith($snap->user_id);

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->error)->toBe('narrator exploded')
        ->and($row->content)->toBeNull();
});

it('does not degrade a staged row under withoutDispatching', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);

    app(NarrationGate::class)->withoutDispatching(function () use ($snap): void {
        $row = dispatchAnalysisRow(
            subjectType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );

        expect($row->status)->toBe(AnalysisStatus::Pending)
            ->and(app(NarrationGate::class)->costCeilingDegraded($snap->user_id))->toBeFalse();
    });
});

it('still dispatches when today\'s LLM cost is under the daily ceiling', function (): void {
    config(['azure_openai.daily_cost_ceiling_per_user' => 100.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);

    $snap = WeeklySnapshot::factory()->create();
    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('keeps existing prose when a capped "Reread" regenerate is a no-op', function (): void {
    config(['ai.auto_dispatch' => false]);
    $snap = WeeklySnapshot::factory()->create();
    Analysis::query()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'status' => AnalysisStatus::Done,
        'content' => 'narasi LLM asli',
    ]);

    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        invalidate: true,
    );

    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->content)->toBe('narasi LLM asli');
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('applies delaySeconds when dispatching (row)', function (): void {
    Carbon::setTestNow('2026-05-18 12:00:00');
    $snap = WeeklySnapshot::factory()->create();

    dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        delaySeconds: 90,
    );

    Bus::assertDispatched(fn (AnalyzeWeeklyRecapJob $job): bool => $job->delay === 90);
    Carbon::setTestNow();
});

it('generates no briefing for a skipped day, staging no row and leaving a narrated one untouched even when invalidated', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-18', 'skipped' => true]);

    $staged = dispatchAnalysisRow(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, '2026-05-18');

    expect($staged->exists)->toBeFalse()
        ->and(Analysis::query()->where('subject_id', $user->id)->exists())->toBeFalse();

    $narrated = Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'status' => AnalysisStatus::Done,
        'content' => 'easy run, 2.6 km.',
    ]);

    dispatchAnalysisRow(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, '2026-05-18', invalidate: true);

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(nonNull($narrated->fresh())->only(['status', 'content']))->toBe(['status' => AnalysisStatus::Done, 'content' => 'easy run, 2.6 km.']);
});

it('briefs a day whose session is not skipped while another day is', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-19', 'skipped' => true]);
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-18']);

    dispatchAnalysisRow(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, '2026-05-18');

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});

it('upsertRow with a NULL discriminator collapses concurrent calls to exactly one row', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $first = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );
    $second = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($second->id)->toBe($first->id)
        ->and(Analysis::query()
            ->where('subject_type', WeeklySnapshot::class)
            ->where('subject_id', $snap->id)
            ->where('analysis_type', AnalysisType::WeeklyRecap)
            ->whereNull('discriminator')
            ->count())->toBe(1);
});

it('a second dispatchGroup on a fully Done group does not re-dispatch', function (): void {
    $activity = Activity::factory()->create();
    dispatchActivityGroup($activity);
    Analysis::query()->where('subject_id', $activity->id)->update([
        'status' => AnalysisStatus::Done->value,
        'content' => 'done.',
    ]);
    Bus::fake();

    dispatchActivityGroup($activity);

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('serves a demo-owned row from the filler instead of dispatching', function (): void {
    $snap = WeeklySnapshot::factory()->for(User::factory()->demo())->create();

    $row = dispatchAnalysisRow(
        subjectType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and(nonNull($row->fresh())->served_by)->toBe(ServedBy::RuleBased);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('serves a demo-owned activity group from the filler instead of dispatching', function (): void {
    $activity = Activity::factory()->for(User::factory()->demo())->create();

    dispatchActivityGroup($activity);

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->every(fn (Analysis $row): bool => $row->served_by === ServedBy::RuleBased))->toBeTrue();
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('serves a demo-owned row from the filler even while generation is paused', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);
    $demo = User::factory()->demo()->create();

    $row = dispatchAnalysisRow(AnalysisType::BRIEFING_SUBJECT_TYPE, $demo->id, AnalysisType::BriefingMascotVoice, Carbon::today()->toDateString());

    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and(nonNull($row->fresh())->served_by)->toBe(ServedBy::RuleBased);
});

it('refills a demo-owned Done row only when the trigger invalidates', function (): void {
    $snap = WeeklySnapshot::factory()->for(User::factory()->demo())->create();
    Analysis::factory()->done('seeded recap')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $kept = dispatchAnalysisRow(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    expect($kept->content)->toBe('seeded recap');

    $refilled = dispatchAnalysisRow(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap, invalidate: true);
    expect($refilled->content)->not->toBe('seeded recap')
        ->and(nonNull($refilled->fresh())->served_by)->toBe(ServedBy::RuleBased);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('leaves a demo-owned row Pending under withoutDispatching, for the demo seed to fill', function (): void {
    $snap = WeeklySnapshot::factory()->for(User::factory()->demo())->create();
    $row = null;

    app(NarrationGate::class)->withoutDispatching(function () use ($snap, &$row): void {
        $row = dispatchAnalysisRow(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    });

    expect($row)->toBeInstanceOf(Analysis::class);
    assert($row instanceof Analysis);
    expect($row->status)->toBe(AnalysisStatus::Pending);
});
