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

it('answers the question and marks it done', function (): void {
    $row = questionRow();

    new AnswerRunQuestionJob($row->id)->handle(
        app(AnalysisService::class),
        fakeQuestionNarrator('your heart rate climbed 6 bpm while the pace held.'),
    );

    expect($row->refresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->answer)->toBe('your heart rate climbed 6 bpm while the pace held.');
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
    config([
        'azure_openai.daily_cost_ceiling' => 1.0,
        'azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]],
    ]);
    TokenUsage::query()->create([
        'kind' => 'run_question', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::now(),
    ]);
    $row = questionRow();

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
