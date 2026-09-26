<?php

declare(strict_types=1);

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnswerRunQuestionJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\RunQuestion;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\CostCeilingLedger;
use App\Services\AI\Narrators\RunQuestionNarrator;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/');
    config()->set('azure_openai.api_key', 'fake-key');
});

function questionRow(array $attributes = []): RunQuestion
{
    $user = User::factory()->create($attributes['user'] ?? []);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create();

    return RunQuestion::factory()->create([
        'user_id' => $user->id,
        'activity_id' => $activity->id,
        ...($attributes['question'] ?? []),
    ]);
}

function fakeQuestionNarrator(mixed $result): RunQuestionNarrator
{
    $mock = Mockery::mock(RunQuestionNarrator::class);
    $expectation = $mock->shouldReceive('generate');
    $result instanceof Throwable ? $expectation->andThrow($result) : $expectation->andReturn($result);

    return $mock;
}

/**
 * A queue delivery of the job at a given attempt; each release() it makes is
 * appended to $released, so a test can count the jobs a delivery re-enqueued.
 *
 * @param  ArrayObject<int, int>|null  $released
 */
function questionDelivery(int $rowId, int $attempts, ?ArrayObject $released = null): AnswerRunQuestionJob
{
    $released ??= new ArrayObject();
    $fake = Mockery::mock(JobContract::class);
    $fake->shouldReceive('attempts')->andReturn($attempts);
    $fake->shouldReceive('release')->andReturnUsing(function (int $delay) use ($released): void {
        $released->append($delay);
    });

    $job = new AnswerRunQuestionJob($rowId);
    $job->setJob($fake);

    return $job;
}

/**
 * A narrator that counts every billed call and runs $during inside the first
 * one, which is where a competing delivery would land while the call is in
 * flight.
 *
 * @param  ArrayObject<int, string>  $calls
 */
function countingQuestionNarrator(ArrayObject $calls, string $answer, ?Closure $during = null, ?Throwable $throw = null): RunQuestionNarrator
{
    $mock = Mockery::mock(RunQuestionNarrator::class);
    $mock->shouldReceive('generate')->andReturnUsing(function () use ($calls, $answer, $during, $throw): string {
        $calls->append($answer);
        if ($during !== null && count($calls) === 1) {
            $during();
        }
        if ($throw !== null) {
            throw $throw;
        }

        return $answer;
    });

    return $mock;
}

it('answers the question and marks it done', function (): void {
    $row = questionRow();

    new AnswerRunQuestionJob($row->id)->handle(
        app(AnalysisService::class),
        fakeQuestionNarrator('your heart rate climbed 6 bpm while the pace held.'),
    );

    expect($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->answer)->toBe('your heart rate climbed 6 bpm while the pace held.');
});

it('passes athlete-supplied context to the narrator unchanged', function (): void {
    $question = 'Ga tidur malam';
    $row = questionRow(['question' => ['question' => $question]]);
    $narrator = Mockery::mock(RunQuestionNarrator::class);
    $narrator->shouldReceive('generate')
        ->once()
        ->withArgs(fn (mixed $activity, mixed $detail, string $received): bool => $received === $question)
        ->andReturn('read from the supplied context');

    new AnswerRunQuestionJob($row->id)->handle(app(AnalysisService::class), $narrator);

    expect($row->refresh()->answer)->toBe('read from the supplied context');
});

it('runs on the ai queue', function (): void {
    expect(new AnswerRunQuestionJob(1)->queue)->toBe('ai');
});

it('leaves an already-answered question alone rather than re-billing it', function (): void {
    $row = questionRow(['question' => ['status' => AnalysisStatus::Done, 'answer' => 'already said']]);

    $narrator = Mockery::mock(RunQuestionNarrator::class);
    $narrator->shouldNotReceive('generate');

    new AnswerRunQuestionJob($row->id)->handle(app(AnalysisService::class), $narrator);

    expect($row->refresh()->answer)->toBe('already said');
});

it('refuses to bill while generation is paused, and says so on the row', function (): void {
    app(AppConfig::class)->set(AppConfigKey::AiEnabled, false);
    $row = questionRow();

    $narrator = Mockery::mock(RunQuestionNarrator::class);
    $narrator->shouldNotReceive('generate');

    new AnswerRunQuestionJob($row->id)->handle(app(AnalysisService::class), $narrator);

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->error)->toBe('AI generation is paused.');
});

it('serves the deterministic answer when the daily cost ceiling is the only stop', function (): void {
    $row = questionRow();
    config([
        'azure_openai.daily_cost_ceiling_per_user' => 1.0,
        'azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]],
    ]);
    TokenUsage::query()->create([
        'user_id' => $row->user_id,
        'kind' => 'run_question', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);

    $narrator = Mockery::mock(RunQuestionNarrator::class);
    $narrator->shouldNotReceive('generate');

    new AnswerRunQuestionJob($row->id)->handle(app(AnalysisService::class), $narrator);

    expect($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->error)->toBeNull()
        ->and($row->answer)->toBeString()->not->toBeEmpty()
        ->and(app(CostCeilingLedger::class)->today()['degradedFills'])->toBe(1);
});

it('fails the question when the run has no detail to read', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $row = RunQuestion::factory()->create(['user_id' => $user->id, 'activity_id' => $activity->id]);

    new AnswerRunQuestionJob($row->id)->handle(app(AnalysisService::class), fakeQuestionNarrator('unused'));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->error)->toContain('not analyzed yet');
});

it('fails the question on a terminal upstream error without rethrowing', function (): void {
    $row = questionRow();

    new AnswerRunQuestionJob($row->id)->handle(
        app(AnalysisService::class),
        fakeQuestionNarrator(new UnavailableException('Azure OpenAI returned non-JSON')),
    );

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->error)->toContain('non-JSON');
});

it('rethrows an unexpected failure after settling the row, so the queue records it', function (): void {
    $row = questionRow();

    expect(fn () => new AnswerRunQuestionJob($row->id)->handle(
        app(AnalysisService::class),
        fakeQuestionNarrator(new RuntimeException('kaboom')),
    ))->toThrow(RuntimeException::class, 'kaboom');

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

it('re-queues and releases a transient upstream failure while a try remains', function (): void {
    $row = questionRow();

    $job = Mockery::mock(AnswerRunQuestionJob::class.'[attempts,release]', [$row->id]);
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once()->with(45);

    $job->handle(
        app(AnalysisService::class),
        fakeQuestionNarrator(new TransientUpstreamException('429', retryAfterSeconds: 45)),
    );

    expect($row->refresh()->status)->toBe(AnalysisStatus::Queued);
});

it('fails a transient upstream failure once the tries are spent', function (): void {
    $row = questionRow();

    $job = Mockery::mock(AnswerRunQuestionJob::class.'[attempts,release]', [$row->id]);
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('attempts')->andReturn(3);
    $job->shouldNotReceive('release');

    $job->handle(app(AnalysisService::class), fakeQuestionNarrator(new TransientUpstreamException('429')));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed);
});

it('settles a row the worker died on, so it never rests in processing', function (): void {
    $row = questionRow(['question' => ['status' => AnalysisStatus::Processing]]);

    new AnswerRunQuestionJob($row->id)->failed(new RuntimeException('worker died'));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Failed)
        ->and($row->error)->toBe('worker died');
});

it('leaves an answered row alone when the failed hook fires late', function (): void {
    $row = questionRow(['question' => ['status' => AnalysisStatus::Done, 'answer' => 'already said']]);

    new AnswerRunQuestionJob($row->id)->failed(new RuntimeException('late'));

    expect($row->refresh()->status)->toBe(AnalysisStatus::Done);
});

it('does nothing when the question row is gone', function (): void {
    $narrator = Mockery::mock(RunQuestionNarrator::class);
    $narrator->shouldNotReceive('generate');

    new AnswerRunQuestionJob(9999)->handle(app(AnalysisService::class), $narrator);
    new AnswerRunQuestionJob(9999)->failed(new RuntimeException('gone'));

    expect(RunQuestion::query()->count())->toBe(0);
});

it('lets only one of two competing deliveries reach the narrator', function (): void {
    $row = questionRow();
    $calls = new ArrayObject();
    $released = new ArrayObject();
    $narrator = countingQuestionNarrator($calls, 'first', function () use ($row, $calls, $released): void {
        questionDelivery($row->id, 1, $released)->handle(
            app(AnalysisService::class),
            countingQuestionNarrator($calls, 'second'),
        );
    });

    questionDelivery($row->id, 1, $released)->handle(app(AnalysisService::class), $narrator);

    expect($calls->getArrayCopy())->toBe(['first'])
        ->and($released)->toHaveCount(0)
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->answer)->toBe('first');
});

it('answers a duplicated delivery of the same job once', function (): void {
    $row = questionRow();
    $calls = new ArrayObject();
    $job = new AnswerRunQuestionJob($row->id);
    $duplicate = unserialize(serialize($job));

    $job->handle(app(AnalysisService::class), countingQuestionNarrator($calls, 'answered'));
    $duplicate->handle(app(AnalysisService::class), countingQuestionNarrator($calls, 'again'));

    expect($calls->getArrayCopy())->toBe(['answered'])
        ->and($row->refresh()->answer)->toBe('answered');
});

it('turns a stale finisher into a no-op once a retry has taken the claim over', function (): void {
    $row = questionRow();
    $calls = new ArrayObject();
    $released = new ArrayObject();
    $stale = countingQuestionNarrator($calls, 'stale', function () use ($row, $calls, $released): void {
        questionDelivery($row->id, 2, $released)->handle(
            app(AnalysisService::class),
            countingQuestionNarrator($calls, 'retry'),
        );
    });

    questionDelivery($row->id, 1, $released)->handle(app(AnalysisService::class), $stale);

    expect($calls->getArrayCopy())->toBe(['stale', 'retry'])
        ->and($released)->toHaveCount(0)
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->answer)->toBe('retry');
});

it('keeps a stale failure or requeue from touching a row a retry now owns', function (Throwable $failure): void {
    $row = questionRow();
    $calls = new ArrayObject();
    $released = new ArrayObject();
    $stale = countingQuestionNarrator($calls, 'stale', function () use ($row, $calls, $released): void {
        questionDelivery($row->id, 2, $released)->handle(
            app(AnalysisService::class),
            countingQuestionNarrator($calls, 'retry'),
        );
    }, $failure);

    try {
        questionDelivery($row->id, 1, $released)->handle(app(AnalysisService::class), $stale);
    } catch (RuntimeException) {
    }

    expect($calls)->toHaveCount(2)
        ->and($released)->toHaveCount(0)
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->answer)->toBe('retry')
        ->and($row->error)->toBeNull();
})->with([
    'transient' => [new TransientUpstreamException('429', retryAfterSeconds: 30)],
    'terminal' => [new UnavailableException('down')],
    'unexpected' => [new RuntimeException('kaboom')],
]);

it('recovers a processing row whose lease has run out', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    config()->set('queue.connections.redis.retry_after', 420);
    $row = questionRow(['question' => [
        'status' => AnalysisStatus::Processing,
        'claim_token' => 'dead-worker',
        'claimed_at' => Carbon::now()->subSeconds(421),
    ]]);
    $calls = new ArrayObject();

    questionDelivery($row->id, 1)->handle(app(AnalysisService::class), countingQuestionNarrator($calls, 'recovered'));

    expect($calls->getArrayCopy())->toBe(['recovered'])
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->claim_token)->not->toBe('dead-worker');
});

it('leaves a live claim to its holder on a first delivery', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    config()->set('queue.connections.redis.retry_after', 420);
    $row = questionRow(['question' => [
        'status' => AnalysisStatus::Processing,
        'claim_token' => 'live-worker',
        'claimed_at' => Carbon::now()->subSeconds(419),
    ]]);
    $calls = new ArrayObject();

    questionDelivery($row->id, 1)->handle(app(AnalysisService::class), countingQuestionNarrator($calls, 'stolen'));

    expect($calls)->toHaveCount(0)
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Processing)
        ->and($row->claim_token)->toBe('live-worker');
});

it('lets a retry take over its predecessor before the lease runs out', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    config()->set('queue.connections.redis.retry_after', 420);
    $row = questionRow(['question' => [
        'status' => AnalysisStatus::Processing,
        'claim_token' => 'timed-out-attempt',
        'claimed_at' => Carbon::now()->subSeconds(419),
    ]]);
    $calls = new ArrayObject();

    questionDelivery($row->id, 2)->handle(app(AnalysisService::class), countingQuestionNarrator($calls, 'retried'));

    expect($calls->getArrayCopy())->toBe(['retried'])
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done);
});

it('reclaims a processing row left without a lease by an earlier deploy', function (): void {
    $row = questionRow(['question' => ['status' => AnalysisStatus::Processing]]);
    $calls = new ArrayObject();

    questionDelivery($row->id, 1)->handle(app(AnalysisService::class), countingQuestionNarrator($calls, 'answered'));

    expect($calls->getArrayCopy())->toBe(['answered'])
        ->and($row->refresh()->status)->toBe(AnalysisStatus::Done);
});
