<?php

declare(strict_types=1);

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnalyzeRowJob;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Contracts\Queue\Job as JobContract;
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

function fakeContentFilterRowJob(int $id): AnalyzeRowJob
{
    return new class ($id) extends AnalyzeRowJob {
        protected function generateContent(Analysis $row): string
        {
            throw new ContentFilterException('content filtered');
        }
    };
}

function fakeTransientRowJob(int $id, ?int $retryAfter = null): AnalyzeRowJob
{
    return new class ($id, $retryAfter) extends AnalyzeRowJob {
        public function __construct(int $analysisId, private readonly ?int $retryAfter)
        {
            parent::__construct($analysisId);
        }

        protected function generateContent(Analysis $row): string
        {
            throw new TransientUpstreamException('rate limited', $this->retryAfter);
        }
    };
}

/**
 * Bind a fake queue Job so `attempts()` and `release()` resolve against it,
 * letting a directly-invoked job exercise the requeue branch and assert the
 * release delay. The returned ArrayObject collects each release delay.
 *
 * @return ArrayObject<int, int>
 */
function attachFakeJob(AnalyzeRowJob $job, int $attempts): ArrayObject
{
    $released = new ArrayObject();
    $fake = Mockery::mock(JobContract::class);
    $fake->shouldReceive('attempts')->andReturn($attempts);
    $fake->shouldReceive('release')->andReturnUsing(function (int $delay) use ($released): void {
        $released->append($delay);
    });
    $job->setJob($fake);

    return $released;
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

it('reverts the row to Pending without billing when generation is paused', function (): void {
    // Azure unset -> generationPaused true. A job dispatched just before the
    // pause must not call the LLM; it reverts to Pending for ai:self-heal.
    config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']);
    $row = makeRowForRowJobTest();

    fakeSuccessRowJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Pending)
        ->and($fresh->attempts)->toBe(0)  // never reached markProcessing
        ->and($fresh->content)->toBeNull();
});

it('marks row Failed without rethrowing for UnavailableException', function (): void {
    $row = makeRowForRowJobTest();

    fakeUnavailableRowJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure down');
});

it('falls back to rule-based content (row Done) when generation content-filters', function (): void {
    // The row (a DailyGreeting) content-filters even after the caller strips
    // continuity. Instead of dead-lettering, the job degrades to rule-based copy.
    $row = makeRowForRowJobTest();

    fakeContentFilterRowJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->not->toBeEmpty()
        ->and($fresh->error)->toBeNull();
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

it('requeues (not fails) and releases on a transient error without Retry-After while tries remain', function (): void {
    $row = makeRowForRowJobTest();

    $job = fakeTransientRowJob($row->id);
    $released = attachFakeJob($job, attempts: 1);
    $job->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued)
        ->and($released->getArrayCopy())->toBe([10]); // falls back to first backoff step
});

it('requeues and releases with the capped Retry-After when the upstream supplies one', function (): void {
    $row = makeRowForRowJobTest();

    $job = fakeTransientRowJob($row->id, retryAfter: 9999);
    $released = attachFakeJob($job, attempts: 1);
    $job->handle(app(AnalysisService::class));

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued)
        ->and($released->getArrayCopy())->toBe([600]); // capped at MAX_RETRY_AFTER_SECONDS
});

it('marks Failed and rethrows on a transient error once tries are exhausted', function (): void {
    $row = makeRowForRowJobTest();

    $job = fakeTransientRowJob($row->id);
    attachFakeJob($job, attempts: 3); // attempts() == tries, no slot left

    expect(fn () => $job->handle(app(AnalysisService::class)))
        ->toThrow(TransientUpstreamException::class);

    expect($row->fresh()->status)->toBe(AnalysisStatus::Failed);
});

it('failed() marks a stranded Processing row Failed so it becomes re-dispatchable', function (): void {
    $row = makeRowForRowJobTest();
    $row->update(['status' => AnalysisStatus::Processing]);

    fakeSuccessRowJob($row->id)->failed(new RuntimeException('worker OOM'));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('worker OOM');
});

it('failed() does not clobber an already-Done row', function (): void {
    $row = makeRowForRowJobTest();
    $row->update(['status' => AnalysisStatus::Done, 'content' => 'kept']);

    fakeSuccessRowJob($row->id)->failed(new RuntimeException('worker OOM'));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->content)->toBe('kept');
});
