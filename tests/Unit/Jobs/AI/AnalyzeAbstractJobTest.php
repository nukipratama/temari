<?php

declare(strict_types=1);

use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnalyzeAbstractJob;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisService;
use App\Services\Run\Story\BriefingComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class FakeSuccessJob extends AnalyzeAbstractJob
{
    protected function generateContent(Analysis $row): string
    {
        return 'generated narrative';
    }

    #[Override]
    protected function modelVersion(): ?string
    {
        return 'test-model';
    }
}

class FakeUnavailableJob extends AnalyzeAbstractJob
{
    protected function generateContent(Analysis $row): string
    {
        throw new UnavailableException('Azure down');
    }
}

class FakeBoomJob extends AnalyzeAbstractJob
{
    protected function generateContent(Analysis $row): string
    {
        throw new RuntimeException('boom');
    }
}

function makeRow(): Analysis
{
    return Analysis::factory()->queued()->create([
        'subject_type' => BriefingComposer::SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingHeadline,
        'discriminator' => '2026-05-18',
    ]);
}

it('marks row as done and stores content on successful generation', function (): void {
    $row = makeRow();
    $job = new FakeSuccessJob($row->id);
    $job->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('generated narrative')
        ->and($fresh->model_version)->toBe('test-model')
        ->and($fresh->attempts)->toBe(1);
});

it('marks row as failed when UnavailableException thrown, without re-raising', function (): void {
    $row = makeRow();
    $job = new FakeUnavailableJob($row->id);

    $job->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure down');
});

it('re-raises unexpected throwables so the queue worker can apply retry policy', function (): void {
    $row = makeRow();
    $job = new FakeBoomJob($row->id);

    expect(fn () => $job->handle(app(AnalysisService::class)))
        ->toThrow(RuntimeException::class, 'boom');

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('no-ops when the row id no longer exists', function (): void {
    $job = new FakeSuccessJob(9999);
    $job->handle(app(AnalysisService::class));

    expect(Analysis::query()->count())->toBe(0);
});

it('skips re-execution when status is already Done (idempotent)', function (): void {
    $row = makeRow();
    $row->update(['status' => AnalysisStatus::Done, 'content' => 'previous']);
    (new FakeSuccessJob($row->id))->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('previous');
});

it('modelVersion falls back to azure_openai.deployment config', function (): void {
    config()->set('azure_openai.deployment', 'gpt-test');
    $row = makeRow();
    (new class ($row->id) extends AnalyzeAbstractJob {
        protected function generateContent(Analysis $row): string
        {
            return 'narrative';
        }
    })->handle(app(AnalysisService::class));

    expect($row->fresh()->model_version)->toBe('gpt-test');
});

it('modelVersion is null when azure_openai.deployment is empty', function (): void {
    config()->set('azure_openai.deployment', '');
    $row = makeRow();
    (new class ($row->id) extends AnalyzeAbstractJob {
        protected function generateContent(Analysis $row): string
        {
            return 'narrative';
        }
    })->handle(app(AnalysisService::class));

    expect($row->fresh()->model_version)->toBeNull();
});
