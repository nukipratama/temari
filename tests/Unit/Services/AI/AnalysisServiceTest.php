<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeTrendCaptionJob;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $this->service = app(AnalysisService::class);
});

it('creates a pending row and queues a row job on first request', function (): void {
    $user = User::factory()->create();

    $row = $this->service->request(
        subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
        subjectId: $user->id,
        type: AnalysisType::TrendCaption,
        discriminator: '2026-05-18',
    );

    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->queued_at)->not->toBeNull();

    Bus::assertDispatched(
        AnalyzeTrendCaptionJob::class,
        fn (AnalyzeTrendCaptionJob $job): bool => $job->analysisId === $row->id,
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

it('requestBriefingGroup creates 2 rows (headline + suggestion) and dispatches one AnalyzeBriefingJob', function (): void {
    $user = User::factory()->create();

    $this->service->requestBriefingGroup($user, '2026-05-18');

    // Mascot voice is split into its own row job — not in this group anymore.
    expect(Analysis::query()->where('subject_id', $user->id)->where('discriminator', '2026-05-18')->count())->toBe(2);
    Bus::assertDispatched(
        AnalyzeBriefingJob::class,
        fn (AnalyzeBriefingJob $job): bool => $job->subjectId === $user->id && $job->discriminator === '2026-05-18',
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

    expect($row->status)->toBe(AnalysisStatus::Done);
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

    expect($row->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('applies delaySeconds when dispatching (row)', function (): void {
    Carbon::setTestNow('2026-05-18 12:00:00');
    $user = User::factory()->create();

    $this->service->request(
        subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
        subjectId: $user->id,
        type: AnalysisType::TrendCaption,
        delaySeconds: 90,
    );

    Bus::assertDispatched(
        AnalyzeTrendCaptionJob::class,
        fn (AnalyzeTrendCaptionJob $job): bool => $job->delay === 90,
    );
    Carbon::setTestNow();
});

it('markDone records content and generated_at', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $this->service->markDone($row, 'final narrative');

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('final narrative')
        ->and($fresh->generated_at)->not->toBeNull();
});

it('markDone uses supplied generatedAt when given', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
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
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $this->service->markFailed($row, 'Azure 500');

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure 500')
        ->and($fresh->content)->toBe('prior content');
});

it('markProcessing increments attempts', function (): void {
    $row = Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
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
