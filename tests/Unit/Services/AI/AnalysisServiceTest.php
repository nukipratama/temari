<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeBriefingJob;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisService;
use App\Services\Run\Story\BriefingComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
});

it('creates a pending row and queues a job on first request', function (): void {
    $service = app(AnalysisService::class);

    $row = $service->request(
        subjectOrType: BriefingComposer::SUBJECT_TYPE,
        subjectId: 1,
        type: AnalysisType::BriefingHeadline,
        jobClass: AnalyzeBriefingJob::class,
        discriminator: '2026-05-18',
    );

    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->queued_at)->not->toBeNull();

    Bus::assertDispatched(AnalyzeBriefingJob::class, fn (AnalyzeBriefingJob $job): bool => $job->analysisId === $row->id);
});

it('skips dispatch when status is already queued/processing/done (idempotent)', function (): void {
    $service = app(AnalysisService::class);
    $row = Analysis::factory()->done('cached content')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $result = $service->request(
        subjectOrType: BriefingComposer::SUBJECT_TYPE,
        subjectId: 1,
        type: AnalysisType::BriefingHeadline,
        jobClass: AnalyzeBriefingJob::class,
        discriminator: '2026-05-18',
    );

    expect($result->id)->toBe($row->id)
        ->and($result->status)->toBe(AnalysisStatus::Done)
        ->and($result->content)->toBe('cached content');

    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
});

it('re-dispatches when status is failed', function (): void {
    $service = app(AnalysisService::class);
    Analysis::factory()->failed('previous error')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $service->request(
        subjectOrType: BriefingComposer::SUBJECT_TYPE,
        subjectId: 1,
        type: AnalysisType::BriefingHeadline,
        jobClass: AnalyzeBriefingJob::class,
        discriminator: '2026-05-18',
    );

    Bus::assertDispatched(AnalyzeBriefingJob::class);
    $row = Analysis::query()->first();
    expect($row->status)->toBe(AnalysisStatus::Queued)
        ->and($row->error)->toBeNull();
});

it('force re-dispatches even when status is done', function (): void {
    $service = app(AnalysisService::class);
    Analysis::factory()->done('old content')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $service->request(
        subjectOrType: BriefingComposer::SUBJECT_TYPE,
        subjectId: 1,
        type: AnalysisType::BriefingHeadline,
        jobClass: AnalyzeBriefingJob::class,
        discriminator: '2026-05-18',
        force: true,
    );

    Bus::assertDispatched(AnalyzeBriefingJob::class);
    expect(Analysis::query()->first()->status)->toBe(AnalysisStatus::Queued);
});

it('markDone records content + model_version + generated_at', function (): void {
    $service = app(AnalysisService::class);
    $row = Analysis::factory()->queued()->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $service->markDone($row, 'final narrative', 'gpt-4-x');

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('final narrative')
        ->and($fresh->model_version)->toBe('gpt-4-x')
        ->and($fresh->generated_at)->not->toBeNull();
});

it('markFailed records error message without clearing prior content', function (): void {
    $service = app(AnalysisService::class);
    $row = Analysis::factory()->done('prior content')->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);

    $service->markFailed($row, 'Azure 500');

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure 500')
        ->and($fresh->content)->toBe('prior content');
});
