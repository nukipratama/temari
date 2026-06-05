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

// Named subclasses live in a *Test.php file would trip the
// `Tests\ => ./tests` PSR-4 rule and trigger composer warnings. Anonymous
// classes don't, and the test never refers to them by name.
function fakeSuccessRowJob(int $id): AnalyzeRowJob
{
    return new class ($id) extends AnalyzeRowJob {
        protected function generateContent(Analysis $row): string
        {
            return 'generated';
        }
    };
}

function fakeUnavailableRowJob(int $id): AnalyzeRowJob
{
    return new class ($id) extends AnalyzeRowJob {
        protected function generateContent(Analysis $row): string
        {
            throw new UnavailableException('Azure down');
        }
    };
}

function fakeBoomRowJob(int $id): AnalyzeRowJob
{
    return new class ($id) extends AnalyzeRowJob {
        protected function generateContent(Analysis $row): string
        {
            throw new RuntimeException('boom');
        }
    };
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

it('marks row Done with content on successful generation', function (): void {
    $row = makeRowForRowJobTest();

    fakeSuccessRowJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('generated')
        ->and($fresh->attempts)->toBe(1);
});

it('marks row Failed without rethrowing for UnavailableException', function (): void {
    $row = makeRowForRowJobTest();

    fakeUnavailableRowJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure down');
});

it('re-raises unexpected throwables so the queue can apply retry policy', function (): void {
    $row = makeRowForRowJobTest();

    expect(fn () => fakeBoomRowJob($row->id)->handle(app(AnalysisService::class)))
        ->toThrow(RuntimeException::class, 'boom');

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('no-ops when the row id no longer exists', function (): void {
    fakeSuccessRowJob(99999)->handle(app(AnalysisService::class));

    expect(Analysis::query()->count())->toBe(0);
});

it('skips re-execution when status is already Done (idempotent)', function (): void {
    $row = makeRowForRowJobTest();
    $row->update(['status' => AnalysisStatus::Done, 'content' => 'previous']);

    fakeSuccessRowJob($row->id)->handle(app(AnalysisService::class));

    expect($row->fresh()->content)->toBe('previous');
});

it('shared retry config: tries=3, backoff=[10, 60]', function (): void {
    $job = fakeSuccessRowJob(1);
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);
});
