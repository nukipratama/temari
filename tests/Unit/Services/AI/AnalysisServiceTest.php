<?php

declare(strict_types=1);

use App\Services\AI\AzureConfigCircuitBreaker;
use Illuminate\Database\QueryException;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Notifications\AnalysisReadyNotification;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaintainerAlerter;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $this->service = app(AnalysisService::class);
});

it('creates a pending row and queues a row job on first request', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $row = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->queued_at)->not->toBeNull();

    Bus::assertDispatched(
        AnalyzeWeeklyRecapJob::class,
        fn (AnalyzeWeeklyRecapJob $job): bool => $job->analysisId === $row->id,
    );
});

it('skips dispatch when status is already done (idempotent)', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('cached recap')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $result = $this->service->request(
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

    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        invalidate: true,
    );

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
    expect(Analysis::query()->first()->status)->toBe(AnalysisStatus::Queued);
});

it('resets attempts to 0 when invalidating a previously-done row (row + group paths)', function (): void {
    // Row path (WeeklyRecap is non-grouped).
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('old')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'attempts' => 3,
    ]);

    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        invalidate: true,
    );

    expect(Analysis::query()->first()->attempts)->toBe(0);

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

    $this->service->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::PostRunSpeech,
        invalidate: true,
    );

    $speechRow = Analysis::query()
        ->where('subject_id', $activity->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->first();
    expect($speechRow->attempts)->toBe(0);
});

it('re-dispatches when status is failed', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->failed('previous error')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
    $row = Analysis::query()->first();
    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->error)->toBeNull();
});

it('requestDeferred creates a Pending row and never dispatches', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $row = $this->service->requestDeferred(
        WeeklySnapshot::class,
        $snap->id,
        AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->queued_at)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('requestDeferred leaves an existing Done row untouched', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $row = $this->service->requestDeferred(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);
    $this->service->markDone($row, 'recap minggu lalu');

    $again = $this->service->requestDeferred(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($again->id)->toBe($row->id)
        ->and($again->status)->toBe(AnalysisStatus::Done)
        ->and($again->content)->toBe('recap minggu lalu');
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('requestActivityGroupRuleBased fills all 4 rows Done without dispatching', function (): void {
    $activity = Activity::factory()->create();

    $this->service->requestActivityGroupRuleBased($activity);

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => is_string($row->content) && $row->content !== ''))->toBeTrue()
        ->and($rows->pluck('analysis_type')->all())->toEqualCanonicalizing([
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsightTechnical,
            AnalysisType::RunInsightSplits,
            AnalysisType::RunInsightZones,
        ]);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('requestActivityGroupRuleBased never overwrites an already-Done row with filler (e.g. real narration that aged past the cap)', function (): void {
    $activity = Activity::factory()->create();
    $realRow = Analysis::factory()->done('narasi asli yang sudah dibayar')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $this->service->requestActivityGroupRuleBased($activity);

    expect($realRow->fresh()->content)->toBe('narasi asli yang sudah dibayar');
});

it('requestActivityGroup creates 4 rows and dispatches one AnalyzeActivityJob', function (): void {
    $activity = Activity::factory()->create();

    $this->service->requestActivityGroup($activity);

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('analysis_type')->all())->toEqualCanonicalizing([
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsightTechnical,
            AnalysisType::RunInsightSplits,
            AnalysisType::RunInsightZones,
        ]);
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id,
    );
    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
});

it('request() with any activity-group type routes to AnalyzeActivityJob (group)', function (): void {
    $activity = Activity::factory()->create();

    $this->service->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::RunInsightSplits,
    );

    expect(Analysis::query()->where('subject_id', $activity->id)->count())->toBe(4);
    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('activity group debounces — 3 sibling-type requests dispatch only one AnalyzeActivityJob', function (): void {
    $activity = Activity::factory()->create();

    $this->service->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::PostRunSpeech,
    );
    $this->service->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::RunInsightTechnical,
    );
    $this->service->request(
        subjectOrType: Activity::class,
        subjectId: $activity->id,
        type: AnalysisType::RunInsightSplits,
    );

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
});

it('requestBriefing creates the suggestion row and dispatches one AnalyzeBriefingMascotVoiceJob', function (): void {
    $user = User::factory()->create();

    $this->service->requestBriefing($user, '2026-05-18');

    // Mascot voice and featured-kartu voice are dispatched by their own callers.
    expect(Analysis::query()->where('subject_id', $user->id)->where('discriminator', '2026-05-18')->count())->toBe(1);

    $row = Analysis::query()
        ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::BriefingMascotVoice)
        ->where('discriminator', '2026-05-18')
        ->firstOrFail();

    Bus::assertDispatched(
        AnalyzeBriefingMascotVoiceJob::class,
        fn (AnalyzeBriefingMascotVoiceJob $job): bool => $job->analysisId === $row->id,
    );
});

it('withoutDispatching suppresses dispatch but still creates Pending rows', function (): void {
    $activity = Activity::factory()->create();

    $this->service->withoutDispatching(function () use ($activity): void {
        $this->service->requestActivityGroup($activity);
    });

    expect(Analysis::query()->where('subject_id', $activity->id)->count())->toBe(4)
        ->and(Analysis::query()->where('subject_id', $activity->id)->first()->status)->toBe(AnalysisStatus::Pending);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('withoutDispatching restores prior suppression state on exit', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $this->service->withoutDispatching(function () use ($snap): void {
        $this->service->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    });
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);

    // After exit, dispatching works again.
    $snap2 = WeeklySnapshot::factory()->create();
    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap2->id,
        type: AnalysisType::WeeklyRecap,
    );
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not dispatch when ai.auto_dispatch config is false', function (): void {
    config(['ai.auto_dispatch' => false]);
    $snap = WeeklySnapshot::factory()->create();

    $row = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not dispatch when the AI kill-switch is off', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);
    $snap = WeeklySnapshot::factory()->create();

    $row = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not dispatch when Azure config is missing', function (): void {
    config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']);
    $snap = WeeklySnapshot::factory()->create();

    $row = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('does not dispatch when today\'s LLM cost exceeds the daily ceiling', function (): void {
    config(['azure_openai.daily_cost_ceiling' => 1.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    // 1M input @ 2.50/1M = $2.50 spent today, over the $1.00 ceiling.
    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);

    $snap = WeeklySnapshot::factory()->create();
    $row = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Pending)
        ->and($row->content)->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('still dispatches when today\'s LLM cost is under the daily ceiling', function (): void {
    config(['azure_openai.daily_cost_ceiling' => 100.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);

    $snap = WeeklySnapshot::factory()->create();
    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('keeps existing prose when a capped "Baca ulang" regenerate is a no-op', function (): void {
    config(['ai.auto_dispatch' => false]);
    $snap = WeeklySnapshot::factory()->create();
    Analysis::query()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'status' => AnalysisStatus::Done,
        'content' => 'narasi LLM asli',
    ]);

    $row = $this->service->request(
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

    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
        delaySeconds: 90,
    );

    Bus::assertDispatched(
        AnalyzeWeeklyRecapJob::class,
        fn (AnalyzeWeeklyRecapJob $job): bool => $job->delay === 90,
    );
    Carbon::setTestNow();
});

it('markDone records content and generated_at', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    $this->service->markDone($row, 'final narrative');

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('final narrative')
        ->and($fresh->generated_at)->not->toBeNull()
        ->and($fresh->cooldownRemaining())->toBeGreaterThanOrEqual(0);
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

    $this->service->markDone($withFingerprint, 'cerita', fingerprint: 'abc123');
    $this->service->markDone($without, 'headline');

    expect($withFingerprint->fresh()->content_fingerprint)->toBe('abc123')
        ->and($without->fresh()->content_fingerprint)->toBeNull();
});

it('markDone uses supplied generatedAt when given', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    $past = Carbon::now()->subHours(2);
    $this->service->markDone($row, 'demo content', $past);

    $fresh = $row->fresh();
    expect($fresh->generated_at->toIso8601String())->toBe($past->toIso8601String());
});

it('markFailed records error message without clearing prior content', function (): void {
    $row = Analysis::factory()->done('prior content')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
    ]);

    $this->service->markFailed($row, 'Azure 500');

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure 500')
        ->and($fresh->content)->toBe('prior content');
});

it('markFailed alerts maintainers exactly at the dead-letter crossing', function (): void {
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

it('markProcessing increments attempts', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-05-18',
        'attempts' => 0,
    ]);

    $this->service->markProcessing($row);

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Processing)
        ->and($fresh->attempts)->toBe(1);
});

it('accepts a Model instance as the subject', function (): void {
    $detail = ActivityDetail::factory()->for(Activity::factory())->create();

    $this->service->request(
        subjectOrType: $detail->activity,
        subjectId: $detail->activity_id,
        type: AnalysisType::RunInsightTechnical,
    );

    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('does not create a duplicate weekly_recap row when re-requested', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $first = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );
    $second = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );

    expect($second->id)->toBe($first->id)
        ->and(Analysis::query()
            ->where('subject_type', WeeklySnapshot::class)
            ->where('subject_id', $snap->id)
            ->where('analysis_type', AnalysisType::WeeklyRecap)
            ->count())->toBe(1);
});

it('does not create a duplicate briefing row when re-requested', function (): void {
    $user = User::factory()->create();

    $this->service->requestBriefing($user, '2026-05-18');
    $this->service->requestBriefing($user, '2026-05-18');

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

it('upsertRow with a NULL discriminator collapses concurrent calls to exactly one row', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $first = $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $snap->id,
        type: AnalysisType::WeeklyRecap,
    );
    $second = $this->service->request(
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

it('upsertGroupRows with NULL discriminators collapses repeat requests to one row per type', function (): void {
    $activity = Activity::factory()->create();

    $this->service->requestActivityGroup($activity);
    $this->service->requestActivityGroup($activity);

    expect(Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->whereNull('discriminator')
        ->count())->toBe(4);
});

it('upsertGroupRows flags rows it created as wasRecentlyCreated', function (): void {
    $activity = Activity::factory()->create();

    $rows = $this->service->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($rows)->toHaveCount(4)
        ->and($rows->every(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => $row->exists && $row->id > 0))->toBeTrue()
        ->and($rows->keys()->all())->toBe(
            array_map(fn (AnalysisType $type): string => $type->value, AnalyzeActivityJob::groupedTypes()),
        );
});

it('upsertGroupRows leaves pre-existing rows unflagged and flags only the newly inserted ones', function (): void {
    $activity = Activity::factory()->create();
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $rows = $this->service->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($rows->get(AnalysisType::PostRunSpeech->value)->wasRecentlyCreated)->toBeFalse()
        ->and($rows->get(AnalysisType::RunInsightZones->value)->wasRecentlyCreated)->toBeTrue()
        ->and($rows->filter(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toHaveCount(3);
});

it('upsertGroupRows flags nothing when every row already exists', function (): void {
    $activity = Activity::factory()->create();
    $this->service->requestActivityGroup($activity);

    $rows = $this->service->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    );

    expect($rows->contains(fn (Analysis $row): bool => $row->wasRecentlyCreated))->toBeFalse();
});

it('inserted group rows carry the Queued status, queued_at and timestamps Eloquent would have set', function (): void {
    Carbon::setTestNow('2026-05-18 07:10:59');
    $activity = Activity::factory()->create();

    $row = $this->service->upsertGroupRows(
        Activity::class,
        $activity->id,
        null,
        AnalyzeActivityJob::groupedTypes(),
    )->get(AnalysisType::PostRunSpeech->value);

    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->queued_at?->toDateTimeString())->toBe('2026-05-18 07:10:59')
        ->and($row->created_at?->toDateTimeString())->toBe('2026-05-18 07:10:59')
        ->and($row->updated_at?->toDateTimeString())->toBe('2026-05-18 07:10:59')
        ->and($row->attempts)->toBe(0);

    Carbon::setTestNow();
});

it('inserted group rows rest Pending with no queued_at while dispatch is suppressed', function (): void {
    $activity = Activity::factory()->create();

    $this->service->withoutDispatching(function () use ($activity): void {
        $rows = $this->service->upsertGroupRows(
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
    $this->service->requestActivityGroup($activity);
    Analysis::query()->where('subject_id', $activity->id)->update([
        'status' => AnalysisStatus::Done->value,
        'content' => 'sudah.',
    ]);
    Bus::fake();

    $this->service->requestActivityGroup($activity);

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
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

    $this->service->markDone($row, 'Cerita lari.');

    Notification::assertSentTo(
        $user,
        AnalysisReadyNotification::class,
        fn (AnalysisReadyNotification $notification): bool => $notification->analysis->id === $row->id,
    );
});

it('markDone does not notify for a non-notifiable type', function (): void {
    Notification::fake();
    $row = Analysis::factory()->create(['analysis_type' => AnalysisType::BriefingMascotVoice]);

    $this->service->markDone($row, 'Halo!');

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

    $this->service->withoutDispatching(function () use ($row): void {
        $this->service->markDone($row, 'Rekap seed.');
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

    $this->service->withoutDispatching(function () use ($row): void {
        $this->service->markDone($row, 'Seed content.');
    });

    $fresh = $row->fresh();
    expect($fresh->cooldownRemaining())->toBeNull()
        ->and($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('Seed content.');
});

it('pauses generation and reports the config reason when the config breaker is tripped', function (): void {
    // Configured (not blank) so the reason is "config", not "unconfigured".
    config(['azure_openai.uri' => 'https://x.openai.azure.com/x', 'azure_openai.api_key' => 'wrong-key']);

    $breaker = app(AzureConfigCircuitBreaker::class);
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }

    expect($this->service->generationPaused())->toBeTrue()
        ->and($this->service->pauseReason())->toBe('config');
});

it('resumes generation for free once the config breaker resets (env fixed)', function (): void {
    config(['azure_openai.uri' => 'https://x.openai.azure.com/x', 'azure_openai.api_key' => 'fixed-key']);

    $breaker = app(AzureConfigCircuitBreaker::class);
    for ($i = 0; $i < 3; $i++) {
        $breaker->recordFailure();
    }
    expect($this->service->generationPaused())->toBeTrue();

    // A successful probe (or an operator reset) closes the breaker; self-heal's
    // generationPaused() gate then clears and dispatch resumes.
    $breaker->reset();

    expect($this->service->generationPaused())->toBeFalse()
        ->and($this->service->pauseReason())->toBeNull();
});

it('markDone does not notify when Telegram is unconfigured', function (): void {
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

    $this->service->markDone($row, 'Rekap.');

    Notification::assertNothingSent();
});

it('requestRuleBased fills a row from the filler without dispatching or cooling', function (): void {
    $snap = WeeklySnapshot::factory()->create();

    $row = $this->service->requestRuleBased(
        WeeklySnapshot::class,
        $snap->id,
        AnalysisType::WeeklyRecap,
    );

    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->content)->toBeString()->not->toBeEmpty()
        ->and($row->generated_at)->not->toBeNull()
        ->and($row->cooldownRemaining())->toBeNull();
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('requestRuleBased refills an already-Done row in place rather than minting a second one', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    $first = $this->service->requestRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    $second = $this->service->requestRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap);

    expect($second->id)->toBe($first->id)
        ->and(Analysis::query()->count())->toBe(1);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('requestRuleBased with refillDone:false leaves an already-Done row untouched', function (): void {
    $snap = WeeklySnapshot::factory()->create();
    Analysis::factory()->done('recap asli, sudah dibayar')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
    ]);

    $row = $this->service->requestRuleBased(WeeklySnapshot::class, $snap->id, AnalysisType::WeeklyRecap, refillDone: false);

    expect($row->content)->toBe('recap asli, sudah dibayar');
});

it('runs the daily cost aggregate once per scope no matter how many rows it dispatches', function (): void {
    config(['azure_openai.daily_cost_ceiling' => 100.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    $snaps = WeeklySnapshot::factory()->count(4)->create();

    $aggregates = 0;
    DB::listen(function (QueryExecuted $query) use (&$aggregates): void {
        if (str_contains($query->sql, 'ai_token_usages')) {
            $aggregates++;
        }
    });

    foreach ($snaps as $snap) {
        $this->service->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
    }

    expect($aggregates)->toBe(1);
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 4);
});

it('re-reads the ceiling in a fresh scope, so a memo never outlives its request or job', function (): void {
    config(['azure_openai.daily_cost_ceiling' => 100.0]);
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

    expect($aggregates)->toBe(2);
});

it('honours a ceiling that is already breached when the scope starts', function (): void {
    config(['azure_openai.daily_cost_ceiling' => 1.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);
    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);

    $snaps = WeeklySnapshot::factory()->count(3)->create();
    foreach ($snaps as $snap) {
        $row = $this->service->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $snap->id,
            type: AnalysisType::WeeklyRecap,
        );
        expect($row->status)->toBe(AnalysisStatus::Pending);
    }

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
    expect($this->service->generationPaused())->toBeTrue();
});

it('keeps withoutDispatching suppressing after the memo is already warm', function (): void {
    config(['azure_openai.daily_cost_ceiling' => 100.0]);

    $warm = WeeklySnapshot::factory()->create();
    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $warm->id,
        type: AnalysisType::WeeklyRecap,
    );
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);

    $suppressed = WeeklySnapshot::factory()->create();
    $this->service->withoutDispatching(function () use ($suppressed): void {
        $row = $this->service->request(
            subjectOrType: WeeklySnapshot::class,
            subjectId: $suppressed->id,
            type: AnalysisType::WeeklyRecap,
        );
        expect($row->status)->toBe(AnalysisStatus::Pending);
        expect($this->service->generationPaused())->toBeTrue();
    });

    // Suppression lifts cleanly: the warm memo is still the real answer.
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);
    expect($this->service->generationPaused())->toBeFalse();

    $after = WeeklySnapshot::factory()->create();
    $this->service->request(
        subjectOrType: WeeklySnapshot::class,
        subjectId: $after->id,
        type: AnalysisType::WeeklyRecap,
    );
    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 2);
});
