<?php

declare(strict_types=1);

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnalyzeRowJob;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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

/**
 * A failing job that records one entry per *billed* run, so a test can assert
 * the total number of real LLM calls a block cost end to end.
 *
 * @param  ArrayObject<int, int>  $calls
 */
function countingRowJob(int $id, ArrayObject $calls, Closure $fail): AnalyzeRowJob
{
    return new class ($id, $calls, $fail) extends AnalyzeRowJob {
        /** @param ArrayObject<int, int> $calls */
        public function __construct(int $analysisId, private readonly ArrayObject $calls, private readonly Closure $fail)
        {
            parent::__construct($analysisId);
        }

        protected function generateContent(Analysis $row): string
        {
            $this->calls->append($row->attempts);

            throw ($this->fail)();
        }
    };
}

function makeRowForRowJobTest(): Analysis
{
    return Analysis::factory()->queued()->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => 1,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
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

it('serves the row rule-based without billing when the spend ceiling tripped mid-flight', function (): void {
    // Azure stays configured, so the only stop is the budget: the row must not
    // rest Pending waiting for a resume that cannot come before midnight.
    config(['azure_openai.daily_cost_ceiling' => 1.0]);
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);
    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);
    $row = makeRowForRowJobTest();

    fakeSuccessRowJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Done)
        ->and($fresh->attempts)->toBe(0)
        ->and($fresh->content)->toBe(app(RuleBasedNarrationFiller::class)->fillFor($fresh))
        ->and($fresh->content)->not->toBe('generated');
});

it('marks row Failed without rethrowing for UnavailableException', function (): void {
    $row = makeRowForRowJobTest();

    fakeUnavailableRowJob($row->id)->handle(app(AnalysisService::class));

    $fresh = $row->fresh();
    expect($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and($fresh->error)->toBe('Azure down');
});

it('falls back to rule-based content (row Done) when generation content-filters', function (): void {
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

it('bounds total billed runs at MAX_SELF_HEAL_ATTEMPTS across self-heal re-dispatches and queue retries', function (): void {
    $row = makeRowForRowJobTest();
    $calls = new ArrayObject();
    $service = app(AnalysisService::class);

    // Walk `attempts` up one billed run at a time: a swallowed
    // UnavailableException is never retried by the queue, so each of these costs
    // exactly one call and leaves the row stalled-but-under-budget. The first is
    // the original dispatch, the second a self-heal re-dispatch.
    countingRowJob($row->id, $calls, fn (): Throwable => new UnavailableException('Azure down'))->handle($service);
    expect($row->fresh()->attempts)->toBe(1)
        ->and(Analysis::query()->stalled()->whereKey($row->id)->exists())->toBeTrue();

    $service->markQueued($row->refresh());
    countingRowJob($row->id, $calls, fn (): Throwable => new UnavailableException('Azure down'))->handle($service);
    expect($row->fresh()->attempts)->toBe(2);

    // Last re-dispatch still under budget, now failing transiently with a full
    // set of its own `$tries` untouched. Only the shared budget can stop it
    // from releasing two more billed runs on top of the two already spent.
    expect(Analysis::query()->stalled()->whereKey($row->id)->exists())->toBeTrue();
    $service->markQueued($row->refresh());
    $job = countingRowJob($row->id, $calls, fn (): Throwable => new TransientUpstreamException('rate limited'));
    $released = attachFakeJob($job, attempts: 1);

    expect(fn () => $job->handle($service))->toThrow(TransientUpstreamException::class);
    expect($released->getArrayCopy())->toBe([]);

    // The queue still retries that rethrow; the row must refuse to bill again.
    $job->handle($service);

    $fresh = $row->fresh();
    expect($calls->count())->toBe(Analysis::MAX_SELF_HEAL_ATTEMPTS)
        ->and($fresh->attempts)->toBe(Analysis::MAX_SELF_HEAL_ATTEMPTS)
        ->and($fresh->status)->toBe(AnalysisStatus::Failed)
        ->and(Analysis::query()->stalled()->whereKey($row->id)->exists())->toBeFalse()
        ->and(Analysis::query()->deadLettered()->whereKey($row->id)->exists())->toBeTrue();
});

it('never spends the retry budget while generation is paused, so a pause cannot dead-letter a block', function (): void {
    config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']);
    $row = makeRowForRowJobTest();
    $calls = new ArrayObject();
    $service = app(AnalysisService::class);

    // More paused runs than the whole budget: a cost ceiling that outlasts
    // several self-heal passes must still cost nothing and stay recoverable.
    foreach (range(1, Analysis::MAX_SELF_HEAL_ATTEMPTS + 2) as $ignored) {
        countingRowJob($row->id, $calls, fn (): Throwable => new RuntimeException('never reached'))->handle($service);
    }

    $paused = $row->fresh();
    expect($calls->count())->toBe(0)
        ->and($paused->attempts)->toBe(0)
        ->and($paused->status)->toBe(AnalysisStatus::Pending)
        ->and(Analysis::query()->stalled()->whereKey($row->id)->exists())->toBeTrue();

    config(['azure_openai.uri' => 'https://azure.test', 'azure_openai.api_key' => 'key']);
    $service->markQueued($row->refresh());
    fakeSuccessRowJob($row->id)->handle($service);

    $resumed = $row->fresh();
    expect($resumed->status)->toBe(AnalysisStatus::Done)
        ->and($resumed->attempts)->toBe(1);
});

it('still bills a manual re-trigger of a dead-lettered block, only refusing queue-driven re-entry', function (): void {
    $row = makeRowForRowJobTest();
    $row->update(['status' => AnalysisStatus::Failed, 'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS]);
    $service = app(AnalysisService::class);

    $calls = new ArrayObject();
    countingRowJob($row->id, $calls, fn (): Throwable => new RuntimeException('never reached'))->handle($service);

    expect($calls->count())->toBe(0);

    // Every dispatch path marks the row Queued first, which is what separates a
    // human "Coba lagi" from the queue re-entering a row it already failed.
    $service->markQueued($row->refresh());
    fakeSuccessRowJob($row->id)->handle($service);

    expect($row->fresh()->status)->toBe(AnalysisStatus::Done);
});

it('settles a budget-spent row stranded in Processing to Failed so it dead-letters', function (): void {
    $row = makeRowForRowJobTest();
    $row->update(['status' => AnalysisStatus::Processing, 'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS]);

    $calls = new ArrayObject();
    countingRowJob($row->id, $calls, fn (): Throwable => new RuntimeException('never reached'))->handle(app(AnalysisService::class));

    expect($calls->count())->toBe(0)
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Failed)
        ->and(Analysis::query()->deadLettered()->whereKey($row->id)->exists())->toBeTrue();
});
