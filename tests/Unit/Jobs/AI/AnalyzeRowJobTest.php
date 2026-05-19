<?php

declare(strict_types=1);

use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnalyzeRowJob;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeSuccessRowJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        return 'generated';
    }

    #[Override]
    protected function modelVersion(): ?string
    {
        return 'test-model';
    }
}

class FakeUnavailableRowJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        throw new UnavailableException('Azure down');
    }
}

class FakeBoomRowJob extends AnalyzeRowJob
{
    protected function generateContent(Analysis $row): string
    {
        throw new RuntimeException('boom');
    }
}

function makeRowForRowJobTest(): Analysis
{
    return Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::DailyGreeting,
        'discriminator' => '2026-05-18',
    ]);
}

it('marks row Done with content + model_version on successful generation', function (): void {
    $row = makeRowForRowJobTest();

    (new FakeSuccessRowJob($row->id))->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('generated')
        ->and($fresh->model_version)->toBe('test-model')
        ->and($fresh->attempts)->toBe(1);
});

it('marks row Failed without rethrowing for UnavailableException', function (): void {
    $row = makeRowForRowJobTest();

    (new FakeUnavailableRowJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->fresh()->error)->toBe('Azure down');
});

it('re-raises unexpected throwables so the queue can apply retry policy', function (): void {
    $row = makeRowForRowJobTest();

    expect(fn () => (new FakeBoomRowJob($row->id))->handle(app(AnalysisService::class)))
        ->toThrow(RuntimeException::class, 'boom');

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('no-ops when the row id no longer exists', function (): void {
    (new FakeSuccessRowJob(99999))->handle(app(AnalysisService::class));

    expect(Analysis::query()->count())->toBe(0);
});

it('skips re-execution when status is already Done (idempotent)', function (): void {
    $row = makeRowForRowJobTest();
    $row->update(['status' => AnalysisStatus::Done, 'content' => 'previous']);

    (new FakeSuccessRowJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('previous');
});

it('shared retry config: tries=3, backoff=[10, 60]', function (): void {
    $job = new FakeSuccessRowJob(1);
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);
});
