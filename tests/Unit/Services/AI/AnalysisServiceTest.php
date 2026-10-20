<?php

declare(strict_types=1);

use App\Services\AI\AzureConfigCircuitBreaker;
use Illuminate\Database\QueryException;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeProfileVoiceJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\AI\TokenUsage;
use App\Models\Feedback;
use App\Models\PlannedSession;
use App\Models\StravaConnection;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\AnalysisReadyNotification;
use App\Notifications\Channels\InAppChannel;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\CeilingOverride;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\LlmCostCalculator;
use App\Services\Ops\MaintainerAlerter;
use App\Services\AI\NarrationGate;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\AI\ServedBy;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

function analysisService(): AnalysisService
{
    return app(AnalysisService::class);
}

it('creates a pending row and queues a row job stamped unattributed on an undeclared first request', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    $result = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    analysisService()->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::PostRunSpeech,
        invalidate: true,
    );

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

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    analysisService()->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::PostRunSpeech,
        invalidate: true,
    );

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

        analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    });

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

        analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
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

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
    $row = nonNull(Analysis::query()->first());
    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->error)->toBeNull();
});

it('requestDeferred creates a Pending row, never dispatches, and leaves it untouched once Done', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisService()->requestDeferred(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->queued_at)->toBeNull();

    analysisService()->markDone($row, 'last week recap', ServedBy::Llm);

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

it('does not claim a pending sibling when another group row is already active', function (): void {
    $activity = Activity::factory()->create();
    $rows = analysisService()->upsertGroupRows(
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

    analysisService()->requestActivityGroup($activity);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    expect(Analysis::query()->where('subject_id', $activity->id)->where('status', AnalysisStatus::Pending)->count())
        ->toBe(1);
});

it('does not invalidate a completed sibling while another group row is active', function (): void {
    $activity = Activity::factory()->create();
    $rows = analysisService()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );
    $token = 'active-generation';
    $active = nonNull($rows->first());
    $done = nonNull($rows->last());
    $active->update(['status' => AnalysisStatus::Queued, 'generation_token' => $token]);
    $done->update([
        'status' => AnalysisStatus::Done,
        'generation_token' => $token,
        'content' => 'completed sibling',
    ]);
    Bus::dispatch(new AnalyzeActivityJob($activity->id, null, $token));

    analysisService()->requestActivityGroup($activity, invalidate: true);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
    expect(nonNull($done->fresh())->status)->toBe(AnalysisStatus::Done)
        ->and(nonNull($done->fresh())->content)->toBe('completed sibling');
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

it('holds an automatic profile voice Pending while the athlete is behind on streak settlement', function (): void {
    Carbon::setTestNow('2026-05-18 00:21:00');
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $held = analysisService()->requestProfileVoice($user, '2026-W21');

    expect($held->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNotDispatched(AnalyzeProfileVoiceJob::class);

    $user->forceFill(['streak_settled_through' => '2026-05-17'])->saveQuietly();

    analysisService()->requestProfileVoice($user, '2026-W21');

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

    analysisService()->requestProfileVoice($user, '2026-W21');

    Bus::assertNotDispatched(AnalyzeProfileVoiceJob::class);
    Carbon::setTestNow();
});

it('lets the athlete\'s own Reread narrate the profile voice while settlement is behind', function (): void {
    Carbon::setTestNow('2026-05-18 09:00:00');
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $row = analysisService()->requestProfileVoice($user, '2026-W21', invalidate: true);

    Bus::assertDispatched(fn (AnalyzeProfileVoiceJob $job): bool => $job->analysisId === $row->id);
    Carbon::setTestNow();
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

it('does not dispatch when ai.auto_dispatch config is false', function (): void {
    config(['ai.auto_dispatch' => false]);
    $snap = WeeklySnapshot::factory()->create();

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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
    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull()
        ->and(analysisService()->costCeilingDegraded())->toBeFalse();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not dispatch, and leaves the row Pending rather than degraded, when Azure is unconfigured', function (): void {
    config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']);

    $snap = WeeklySnapshot::factory()->create();
    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull()
        ->and(analysisService()->costCeilingDegraded())->toBeFalse();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('degrades only the athlete who spent, and leaves everyone else billing normally', function (): void {
    $heavy = WeeklySnapshot::factory()->create();
    $bystander = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($heavy->user_id);

    $heavyRow = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $heavy->id,
        type: AnalysisType::WeeklyRecap,
    );
    $bystanderRow = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $bystander->id,
        type: AnalysisType::WeeklyRecap,
    );

    // The whole reason the ceiling is per athlete: a shared pool let the
    // heaviest athlete of the day silently degrade everyone else's narration.
    expect($heavyRow->status)->toBe(AnalysisStatus::Done)
        ->and($bystanderRow->status)->toBe(AnalysisStatus::Queued)
        ->and(analysisService()->costCeilingDegraded($heavy->user_id))->toBeTrue()
        ->and(analysisService()->costCeilingDegraded($bystander->user_id))->toBeFalse();
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

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and(analysisService()->costCeilingDegraded())->toBeFalse();
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

    analysisService()->requestActivityGroup($activity);

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

    analysisService()->requestActivityGroup($activity);

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
        analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
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

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and(analysisService()->costCeilingDegraded($snap->user_id))->toBeFalse();
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('degrades an athlete who has spent nothing once the app-wide ceiling is passed', function (): void {
    $heavy = WeeklySnapshot::factory()->create();
    $bystander = WeeklySnapshot::factory()->create();
    breachTheTotalCeilingWith($heavy->user_id);

    $bystanderRow = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $bystander->id,
        type: AnalysisType::WeeklyRecap,
    );

    // Their own slice is untouched: only the app-wide stop above it degrades them.
    expect(app(LlmCostCalculator::class)->dailyCost($bystander->user_id))->toBe(0.0)
        ->and($bystanderRow->status)->toBe(AnalysisStatus::Done)
        ->and($bystanderRow->content)->toBe(
            app(RuleBasedNarrationFiller::class)->fillFor($bystanderRow),
        )
        ->and(analysisService()->costCeilingDegraded($bystander->user_id))->toBeTrue();
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

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->error)->toBe('narrator exploded')
        ->and($row->content)->toBeNull();
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

it('does not degrade a staged row under withoutDispatching', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    breachTheCeilingFor($snap->user_id);

    analysisService()->withoutDispatching(function () use ($snap): void {
        $row = analysisService()->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );

        expect($row->status)->toBe(AnalysisStatus::Pending)
            ->and(analysisService()->costCeilingDegraded($snap->user_id))->toBeFalse();
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
    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

    analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        delaySeconds: 90,
    );

    Bus::assertDispatched(fn (AnalyzeWeeklyRecapJob $job): bool => $job->delay === 90);
    Carbon::setTestNow();
});

it('markDone records content and generated_at', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);
    app(NarrationOrigin::class)->set(AnalysisOrigin::User);

    analysisService()->markDone($row, 'final narrative', ServedBy::Llm);

    $fresh = nonNull($row->fresh());
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('final narrative')
        ->and($fresh->generated_at)->not->toBeNull()
        ->and($fresh->cooldownRemaining())->toBeGreaterThan(0);
});

it('markDone stores a content fingerprint when given, and leaves it null otherwise', function (): void {
    $withFingerprint = Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
    $without = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    analysisService()->markDone($withFingerprint, 'story', ServedBy::Llm, fingerprint: 'abc123');
    analysisService()->markDone($without, 'headline', ServedBy::Llm);

    expect(nonNull($withFingerprint->fresh())->content_fingerprint)->toBe('abc123')
        ->and(nonNull($without->fresh())->content_fingerprint)->toBeNull();
});

it('markDone uses supplied generatedAt when given', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    $past = Carbon::now()->subHours(2);
    analysisService()->markDone($row, 'demo content', ServedBy::Llm, $past);

    $fresh = nonNull($row->fresh());
    expect(nonNull($fresh->generated_at)->toIso8601String())->toBe($past->toIso8601String());
});

it('markFailed records error message without clearing prior content', function (): void {
    $row = Analysis::factory()->done('prior content')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    analysisService()->markFailed($row, 'Azure 500');

    $fresh = nonNull($row->fresh());
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure 500')
        ->and($fresh->content)->toBe('prior content');
});

it('markFailed alerts maintainers exactly at the dead-letter crossing', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->app->forgetInstance(AnalysisService::class);
    $service = app(AnalysisService::class);

    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    $alerter->shouldReceive('deadLettered')->once();

    $service->markFailed($row, 'Azure 500');
});

it('markFailed does not alert while a Failed row is still under the retry budget', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $this->app->instance(MaintainerAlerter::class, $alerter);
    $this->app->forgetInstance(AnalysisService::class);
    $service = app(AnalysisService::class);

    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => 1,
    ]);

    $alerter->shouldNotReceive('deadLettered');

    $service->markFailed($row, 'Azure 500');
});

it('increments attempts atomically from a stale row model', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => 0,
    ]);

    $stale = $row->fresh();
    analysisService()->markProcessing($row);
    expect(analysisService()->markProcessing(nonNull($stale)))->toBeFalse()
        ->and(analysisService()->markProcessing(nonNull($stale), allowProcessing: true))->toBeTrue();

    $fresh = nonNull($row->fresh());
    expect($fresh->status)->toBe(AnalysisStatus::Processing)
        ->and($fresh->attempts)->toBe(2);
});

it('keeps the attempt when a processing row is reset for recovery', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => 0,
    ]);

    analysisService()->markProcessing($row);
    analysisService()->revertToPending($row);

    expect(nonNull($row->fresh())->status)->toBe(AnalysisStatus::Pending)
        ->and($row->attempts)->toBe(1);
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

it('generates no briefing for a skipped day, staging no row and leaving a narrated one untouched even when invalidated', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-18', 'skipped' => true]);

    $staged = analysisService()->requestBriefing($user, '2026-05-18');

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

    analysisService()->requestBriefing($user, '2026-05-18', invalidate: true);
    analysisService()->request(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, '2026-05-18', invalidate: true);

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(nonNull($narrated->fresh())->only(['status', 'content']))->toBe(['status' => AnalysisStatus::Done, 'content' => 'easy run, 2.6 km.']);
});

it('briefs a day whose session is not skipped while another day is', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-19', 'skipped' => true]);
    PlannedSession::factory()->for($user)->create(['date' => '2026-05-18']);

    analysisService()->requestBriefing($user, '2026-05-18');

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
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

it('upsertRow with a NULL discriminator collapses concurrent calls to exactly one row', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $first = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );
    $second = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
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

it('upsertGroupRows leaves pre-existing rows unflagged and flags only the newly inserted ones', function (): void {
    $activity = Activity::factory()->create();
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $rows = analysisService()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->wasRecentlyCreated)->toBeFalse()
        ->and(nonNull($rows->get(AnalysisType::RunInsight->value))->wasRecentlyCreated)->toBeTrue()
        ->and($rows->filter(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toHaveCount(1);
});

it('upsertGroupRows flags nothing when every row already exists', function (): void {
    $activity = Activity::factory()->create();
    analysisService()->requestActivityGroup($activity);

    $rows = analysisService()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($rows->contains(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toBeFalse();
});

it('marks only rows inserted by this caller as recently created when an insert races', function (): void {
    $activity = Activity::factory()->create();
    $insertedByRacer = false;

    DB::listen(function (QueryExecuted $query) use ($activity, &$insertedByRacer): void {
        if ($insertedByRacer || ! str_contains(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'ai_analyses')) {
            return;
        }

        $insertedByRacer = true;
        Analysis::factory()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::RunInsight,
            'discriminator' => null,
        ]);
    });

    $rows = analysisService()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($insertedByRacer)->toBeTrue()
        ->and(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->wasRecentlyCreated)->toBeTrue()
        ->and(nonNull($rows->get(AnalysisType::RunInsight->value))->wasRecentlyCreated)->toBeFalse();
});

it('upsertGroupRows flags the rows it created, which stay Pending until the whole group is claimed', function (): void {
    Carbon::setTestNow('2026-05-18 07:10:59');
    $activity = Activity::factory()->create();

    $rows = analysisService()->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => $row->exists && $row->id > 0))->toBeTrue()
        ->and($rows->keys()->all())->toBe(
            array_map(fn (AnalysisType $type): string => $type->value, AnalyzeActivityJob::groupedTypes()),
        );

    $row = nonNull($rows->get(AnalysisType::PostRunSpeech->value));

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->queued_at)->toBeNull()
        ->and($row->generation_token)->toBeNull()
        ->and($row->created_at->toDateTimeString())->toBe('2026-05-18 07:10:59')
        ->and($row->updated_at->toDateTimeString())->toBe('2026-05-18 07:10:59')
        ->and($row->attempts)->toBe(0);

    Carbon::setTestNow();
});

it('inserted group rows rest Pending with no queued_at while dispatch is suppressed', function (): void {
    $activity = Activity::factory()->create();

    analysisService()->withoutDispatching(function () use ($activity): void {
        $rows = analysisService()->upsertGroupRows(
            Activity::class,
            $activity->id,
            null,
            AnalyzeActivityJob::groupedTypes(),
        );

        expect($rows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Pending))->toBeTrue()
            ->and($rows->every(fn (Analysis $row): bool => $row->queued_at === null))->toBeTrue();
    });
});

it('a second dispatchGroup on a fully Done group does not re-dispatch', function (): void {
    $activity = Activity::factory()->create();
    analysisService()->requestActivityGroup($activity);
    Analysis::query()->where('subject_id', $activity->id)->update([
        'status' => AnalysisStatus::Done->value,
        'content' => 'done.',
    ]);
    Bus::fake();

    analysisService()->requestActivityGroup($activity);

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('does not let a finishing job overwrite a newer group generation', function (): void {
    $activity = Activity::factory()->create();
    analysisService()->requestActivityGroup($activity);
    $stale = Analysis::query()->where('subject_id', $activity->id)->firstOrFail();
    $oldToken = $stale->generation_token;

    Analysis::query()->where('subject_id', $activity->id)->update([
        'status' => AnalysisStatus::Done->value,
        'content' => 'previous generation',
    ]);

    analysisService()->requestActivityGroup($activity, invalidate: true);

    $fresh = Analysis::query()->whereKey($stale->id)->firstOrFail();
    expect($oldToken)->not->toBeNull()
        ->and($fresh->generation_token)->not->toBe($oldToken)
        ->and($fresh->status)->toBe(AnalysisStatus::Queued)
        ->and(analysisService()->markDone(
            $stale,
            'late previous generation',
            ServedBy::Llm,
            generationToken: $oldToken,
        ))->toBeFalse()
        ->and(nonNull($fresh->fresh())->content)->toBe('previous generation');
});

it('markDone fans out a notification for a notifiable, wired type', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertSentTo(
        $user,
        AnalysisReadyNotification::class,
        fn (AnalysisReadyNotification $notification): bool => $notification->analysis->id === $row->id,
    );
});

it('markDone sends no notification for a narration requested on the athlete return', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);
    app(NarrationOrigin::class)->set(AnalysisOrigin::Return);

    analysisService()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertNothingSent();
});

it('markDone marks the row early and skips the notification during a fresh connect\'s early pass', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(3)]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDay()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertNothingSent();
    expect(nonNull($row->fresh())->narrated_early_at)->not->toBeNull();
});

it('markDone leaves narrated_early_at null and notifies normally for a long-connected athlete', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    StravaConnection::factory()->for($user)->create(['created_at' => now()->subDays(90)]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->markDone($row, 'Run story.', ServedBy::Llm);

    Notification::assertSentTo($user, AnalysisReadyNotification::class);
    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull();
});

it('markDone never marks a rule-based fill early, even for a run inside the early-pass window', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(3)]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDay()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->markDone($row, 'filler.', ServedBy::RuleBased);

    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull();
});

it('re-requests a straddling row once instead of marking it early', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    Bus::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    StravaConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    // No unhydrated backlog exists right now, so isEarlyPassRow() alone would
    // say this row is not early — but its generation started while it was
    // (e.g. a slow Azure call spanning the moment the drain emptied). The
    // replay that would ever regenerate an early row has already run by now,
    // so this row asks for itself again instead of being left early.
    analysisService()->markDone($row, 'Run story.', ServedBy::Llm, startedEarly: true);

    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull()
        ->and(nonNull($row->fresh())->status)->toBe(AnalysisStatus::Queued);
    // No notification on this pass either — it fires once, on the
    // re-requested pass that finally reads the complete history.
    Notification::assertNothingSent();
    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id);
});

it('never marks a rule-based fill early from $startedEarly either', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->markDone($row, 'filler.', ServedBy::RuleBased, startedEarly: true);

    expect(nonNull($row->fresh())->narrated_early_at)->toBeNull();
});

it('markDone marks the daily briefing early while older history is hydrating', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(3)]);
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => now()->toDateString(),
    ]);

    analysisService()->markDone($row, 'Halo!', ServedBy::Llm);

    expect(nonNull($row->fresh())->narrated_early_at)->not->toBeNull();
});

it('markDone marks the profile voice early while any history at all is hydrating, even past past-you\'s reach', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $olderBacklog = Activity::factory()->for($user)->summaryOnly()->create();
    // Beyond PastYouMatcher::MAX_GAP_DAYS (365): awaitsOlderHydration() would
    // ignore this, but the profile voice reads the whole lifetime, so
    // awaitsFullHydration() must still catch it.
    ActivityDetail::factory()->for($olderBacklog)->create(['start_date_local' => now()->subDays(400)]);
    $row = Analysis::factory()->create([
        'subject_type' => AnalysisType::ProfileVoice->subjectType(),
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::ProfileVoice,
        'discriminator' => AnalysisType::currentIsoWeek(),
    ]);

    analysisService()->markDone($row, 'Profile voice.', ServedBy::Llm);

    expect(nonNull($row->fresh())->narrated_early_at)->not->toBeNull();
});

it('markDone does not notify for a non-notifiable type', function (): void {
    Notification::fake();
    $row = Analysis::factory()->create(['analysis_type' => AnalysisType::BriefingMascotVoice]);

    analysisService()->markDone($row, 'Halo!', ServedBy::Llm);

    Notification::assertNothingSent();
});

it('markDone does not notify under withoutDispatching (demo seed)', function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token', 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->withoutDispatching(function () use ($row): void {
        analysisService()->markDone($row, 'Rekap seed.', ServedBy::Llm);
    });

    Notification::assertNothingSent();
});

it('markDone does not start the re-trigger cooldown under withoutDispatching (demo seed)', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    analysisService()->withoutDispatching(function () use ($row): void {
        analysisService()->markDone($row, 'Seed content.', ServedBy::Llm);
    });

    $fresh = nonNull($row->fresh());
    expect($fresh->cooldownRemaining())->toBeNull()
        ->and($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('Seed content.');
});

it('markDone reaches the inbox alone when Telegram is unconfigured', function (): void {
    config(['services.telegram.bot_token' => null, 'services.telegram.notify_max_age_days' => 14]);
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()]);
    $row = Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    analysisService()->markDone($row, 'Rekap.', ServedBy::Llm);

    Notification::assertSentTo(
        $user,
        AnalysisReadyNotification::class,
        fn (AnalysisReadyNotification $notification): bool => $notification->via($user) === [InAppChannel::class],
    );
});

it('requestRuleBased fills a row rule-based with no reason, without dispatching or cooling, and refills it in place rather than minting a second one', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $first = analysisService()->requestRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($first->status)->toBe(AnalysisStatus::Done)
        ->and($first->content)->toBeString()->and($first->content)->not->toBeEmpty()
        ->and($first->generated_at)->not->toBeNull()
        ->and($first->cooldownRemaining())->toBeNull()
        ->and(nonNull($first->fresh())->served_by)->toBe(ServedBy::RuleBased)
        ->and(nonNull($first->fresh())->rule_based_reason)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);

    $second = analysisService()->requestRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($second->id)->toBe($first->id)
        ->and(Analysis::query()->count())->toBe(1);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('requestRuleBased with refillDone:false leaves an already-Done row untouched', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('original recap, already billed')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $row = analysisService()->requestRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap, refillDone: false);

    expect($row->content)->toBe('original recap, already billed');
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

// ── served_by: which producer wrote the content on the row ────────────

it('stamps the producer the caller states, and has no default to fall back on', function (): void {
    $llm = Analysis::factory()->queued()->create(['discriminator' => '2026-05-18']);
    $ruleBased = Analysis::factory()->queued()->create(['discriminator' => '2026-05-19']);

    analysisService()->markDone($llm, 'narrated', ServedBy::Llm);
    analysisService()->markDone($ruleBased, 'filled', ServedBy::RuleBased);

    expect(nonNull($llm->fresh())->served_by)->toBe(ServedBy::Llm)
        ->and(nonNull($ruleBased->fresh())->served_by)->toBe(ServedBy::RuleBased)
        ->and(new ReflectionMethod(AnalysisService::class, 'markDone')->getParameters()[2]->isOptional())
        ->toBeFalse();
});

it('does not count a rule-based fill after a newer generation takes ownership', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisService()->requestDeferred(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    $row->update(['generation_token' => 'older-generation']);
    $stale = $row->fresh();
    $row->update([
        'status' => AnalysisStatus::Queued,
        'generation_token' => 'newer-generation',
    ]);
    $ledger = app(CostCeilingLedger::class);
    $degradedFills = $ledger->today()['degradedFills'];

    analysisService()->degradeToRuleBased(nonNull($stale), 'older-generation');

    expect(nonNull($row->fresh())->status)->toBe(AnalysisStatus::Queued)
        ->and(nonNull($row->fresh())->generation_token)->toBe('newer-generation')
        ->and(nonNull($row->fresh())->content)->toBeNull()
        ->and($ledger->today()['degradedFills'])->toBe($degradedFills);
});

// ── rule_based_reason: why (not merely that) a row was served rule-based ──

it('never records a reason for an LLM-served row, even if one is passed', function (): void {
    $row = Analysis::factory()->queued()->create();

    analysisService()->markDone($row, 'narrated', ServedBy::Llm, ruleBasedReason: AnalysisOrigin::Return);

    expect(nonNull($row->fresh())->rule_based_reason)->toBeNull();
});

it('clears a stale rule_based_reason when the row is re-served by the LLM', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisService()->requestRuleBased(
        WeeklySnapshot::class,
        $snap->id,
        AnalysisType::WeeklyRecap,
        reason: AnalysisOrigin::Return,
    );
    expect(nonNull($row->fresh())->rule_based_reason)->toBe(AnalysisOrigin::Return);

    analysisService()->markDone($row, 'now narrated for real', ServedBy::Llm);

    $fresh = nonNull($row->fresh());

    expect($fresh->served_by)->toBe(ServedBy::Llm)
        ->and($fresh->rule_based_reason)->toBeNull();
});

it('clears a stale rule_based_reason when re-served rule-based for a different reason', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = analysisService()->requestRuleBased(
        WeeklySnapshot::class,
        $snap->id,
        AnalysisType::WeeklyRecap,
        reason: AnalysisOrigin::Return,
    );

    analysisService()->requestRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect(nonNull($row->fresh())->rule_based_reason)->toBeNull();
});

// ── analysis_versions: what a re-narration supersedes ─────────────────

it('keeps the previous narration and supersedes only its own flag when a done row is re-narrated', function (): void {
    $row = Analysis::factory()->done('first')->create([
        'content_fingerprint' => 'abc123',
        'served_by' => ServedBy::RuleBased,
    ]);
    $flag = Feedback::factory()->onNarration($row->id)->create();
    $otherNarration = Feedback::factory()->onNarration($row->id + 1)->create();
    $planDay = Feedback::factory()->onPlanDay($row->id)->create();

    analysisService()->markDone($row, 'second', ServedBy::Llm);

    $version = AnalysisVersion::query()->sole();

    expect($version->analysis_id)->toBe($row->id)
        ->and($version->content)->toBe('first')
        ->and($version->fingerprint)->toBe('abc123')
        ->and($version->served_by)->toBe(ServedBy::RuleBased)
        ->and(nonNull($row->fresh())->content)->toBe('second')
        ->and(nonNull($flag->fresh())->superseded_at)->not->toBeNull()
        ->and(nonNull($otherNarration->fresh())->superseded_at)->toBeNull()
        ->and(nonNull($planDay->fresh())->superseded_at)->toBeNull();
});

it('writes no version and leaves the flag standing on a first narration, which supersedes nothing', function (): void {
    $row = Analysis::factory()->queued()->create();
    $flag = Feedback::factory()->onNarration($row->id)->create();

    analysisService()->markDone($row, 'first', ServedBy::Llm);

    expect(AnalysisVersion::query()->count())->toBe(0)
        ->and(nonNull($flag->fresh())->superseded_at)->toBeNull();
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

it('serves a demo-owned row from the filler instead of dispatching', function (): void {
    $snap = WeeklySnapshot::factory()->for(User::factory()->demo())->create();

    $row = analysisService()->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and(nonNull($row->fresh())->served_by)->toBe(ServedBy::RuleBased);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('serves a demo-owned activity group from the filler instead of dispatching', function (): void {
    $activity = Activity::factory()->for(User::factory()->demo())->create();

    analysisService()->requestActivityGroup($activity);

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Analysis $row): bool => $row->served_by === ServedBy::RuleBased))->toBeTrue();
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('serves a demo-owned row from the filler even while generation is paused', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);
    $demo = User::factory()->demo()->create();

    $row = analysisService()->requestBriefing($demo, Carbon::today()->toDateString());

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

    $kept = analysisService()->request(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    expect($kept->content)->toBe('seeded recap');

    $refilled = analysisService()->request(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap, invalidate: true);
    expect($refilled->content)->not->toBe('seeded recap')
        ->and(nonNull($refilled->fresh())->served_by)->toBe(ServedBy::RuleBased);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('leaves a demo-owned row Pending under withoutDispatching, for the demo seed to fill', function (): void {
    $snap = WeeklySnapshot::factory()->for(User::factory()->demo())->create();
    $row = null;

    analysisService()->withoutDispatching(function () use ($snap, &$row): void {
        $row = analysisService()->request(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    });

    expect($row)->toBeInstanceOf(Analysis::class);
    assert($row instanceof Analysis);
    expect($row->status)->toBe(AnalysisStatus::Pending);
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
    analysisService()->markDone($briefing, 'easy run, 2.6 km.', ServedBy::Llm);

    app(NarrationOrigin::class)->set(AnalysisOrigin::User);
    analysisService()->rebriefAfterPlanEdit($user, '2026-05-18');

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, $rebriefs ? 1 : 0);
})->with([
    'the 00:01 briefing' => [AnalysisOrigin::Scheduled, true],
    'a user Reread' => [AnalysisOrigin::User, false],
]);

it('opens the Reread cooldown only after a user-started narration', function (AnalysisOrigin $origin, bool $cooling): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);
    app(NarrationOrigin::class)->set($origin);

    analysisService()->markDone($row, 'story', ServedBy::Llm);

    expect(nonNull($row->fresh())->cooldownRemaining() !== null)->toBe($cooling);
})->with([
    'ingest' => [AnalysisOrigin::Ingest, false],
    'self-heal' => [AnalysisOrigin::Recovery, false],
    'scheduled' => [AnalysisOrigin::Scheduled, false],
    'user' => [AnalysisOrigin::User, true],
]);

it('rebriefs after a plan edit when no briefing was narrated yet', function (): void {
    $user = User::factory()->create();

    analysisService()->rebriefAfterPlanEdit($user, '2026-05-18');

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});
