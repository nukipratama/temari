<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\RejectedOutputException;
use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisRowState;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\NarratedAnalysis;
use App\Services\AI\Narrators\NarrationContent;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\ServedBy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function mockSpeech(string $content = 'nice run'): void
{
    $mock = Mockery::mock(PostRunSpeechNarrator::class);
    $mock->shouldReceive('generate')->andReturn($content);
    app()->instance(PostRunSpeechNarrator::class, $mock);
}

/** @return Collection<string, Analysis> */
function activityRows(Activity $activity): Collection
{
    return Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value)
        ->toBase();
}

function legacyInsightRow(Activity $activity, AnalysisStatus $status, ?string $content = null): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
        'discriminator' => null,
        'status' => $status,
        'content' => $content,
    ]);
}

function seedActivityForJob(): Activity
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
    ]);
    StoryLine::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => 'blazing',
    ]);

    return $activity;
}

it('groups the post-run speech alone', function (): void {
    expect(AnalyzeActivityJob::groupedTypes())->toBe([AnalysisType::PostRunSpeech]);
});

it('writes the merged speech row Done from one job run and creates no run-insight row', function (): void {
    config([
        'azure_openai.uri' => 'https://x.openai.azure.com',
        'azure_openai.api_key' => 'fake',
        'azure_openai.timeout' => 5,
        'ai.agent.deadline_seconds' => 20,
        'horizon.defaults.supervisor-ai.timeout' => 90,
    ]);
    $activity = seedActivityForJob();
    $stored = new NarrationContent('a quiet one', 'Body.', [['anchor' => 'split:1', 'text' => 'Km 1.', 'value' => null, 'delta' => null]])->toStored();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')
        ->withArgs(fn ($a, $d, $mood, $deadlineSeconds): bool => $mood === 'blazing' && $deadlineSeconds === 20)
        ->andReturn($stored);
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $rows = activityRows($activity);

    expect($rows->keys()->all())->toBe([AnalysisType::PostRunSpeech->value])
        ->and(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->content)->toBe($stored)
        ->and(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->status)->toBe(AnalysisStatus::Done);
});

it('caps the speech deadline at what the worker has left after its request timeout', function (): void {
    config([
        'azure_openai.uri' => 'https://x.openai.azure.com',
        'azure_openai.api_key' => 'fake',
        'azure_openai.timeout' => 5,
        'ai.agent.deadline_seconds' => 90,
        'horizon.defaults.supervisor-ai.timeout' => 100,
    ]);
    Carbon::setTestNow('2026-09-26 06:00:00');
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')
        ->once()
        ->withArgs(fn ($a, $d, $mood, $deadlineSeconds): bool => $deadlineSeconds === 85)
        ->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    try {
        new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

        expect(nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value))->status)->toBe(AnalysisStatus::Done);
    } finally {
        Carbon::setTestNow();
    }
});

it('leaves the speech Pending without an attempt when less than its minimum deadline remains', function (): void {
    config([
        'azure_openai.uri' => 'https://x.openai.azure.com',
        'azure_openai.api_key' => 'fake',
        'azure_openai.timeout' => 5,
        'ai.agent.deadline_seconds' => 20,
        'horizon.defaults.supervisor-ai.timeout' => 60,
    ]);
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldNotReceive('generate');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $speech = nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value));
    expect($speech->status)->toBe(AnalysisStatus::Pending)
        ->and($speech->attempts)->toBe(0)
        ->and($speech->content)->toBeNull();
});

it('ignores a group job after a newer generation owns its rows', function (): void {
    $activity = seedActivityForJob();
    $currentToken = 'current-generation';

    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::factory()->queued()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
            'generation_token' => $currentToken,
        ]);
    }

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldNotReceive('generate');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id, null, 'stale-generation')->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Queued))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => $row->generation_token === $currentToken))->toBeTrue();
});

it('stamps the speech row with the activity material fingerprint at generation', function (): void {
    $activity = seedActivityForJob();
    mockSpeech();

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    expect(nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value))->content_fingerprint)
        ->toBe(MaterialFingerprint::forActivity(nonNull($activity->fresh(['detail']))));
});

it('reverts the speech row to Pending without billing when generation is paused', function (): void {
    config(['azure_openai.uri' => '', 'azure_openai.api_key' => '']);
    $activity = seedActivityForJob();
    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::factory()->queued()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
        ]);
    }

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->pluck('status')->unique()->all())->toBe([AnalysisStatus::Pending])
        ->and($rows->pluck('attempts')->unique()->all())->toBe([0]);
});

it('fails the speech honestly rather than templating it when the LLM is unavailable', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andThrow(new UnavailableException('llm down'));
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $speech = nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value));
    expect($speech->status)->toBe(AnalysisStatus::Failed)
        ->and($speech->content)->toBeNull();
});

it('narrates an old run\'s speech and leaves its run-insight row exactly as it was', function (AnalysisStatus $status, ?string $content): void {
    $activity = seedActivityForJob();
    $before = legacyInsightRow($activity, $status, $content)->refresh()->only(['status', 'content', 'attempts', 'generation_token', 'updated_at']);
    mockSpeech('new story');

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $rows = activityRows($activity);
    expect(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->content)->toBe('new story')
        ->and(nonNull($rows->get(AnalysisType::RunInsight->value))->only(['status', 'content', 'attempts', 'generation_token', 'updated_at']))
        ->toEqual($before);
})->with([
    'a Done insight' => [AnalysisStatus::Done, '[{"anchor":"metric:decoupling","text":"stored claim","value":null,"delta":null}]'],
    'a Pending insight from a staged backfill' => [AnalysisStatus::Pending, null],
    'a Failed insight' => [AnalysisStatus::Failed, null],
]);

it('marks the speech row failed when the activity is missing, minting no run-insight row', function (): void {
    new AnalyzeActivityJob(99999)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $rows = Analysis::query()->where('subject_id', 99999)->get();
    expect($rows)->toHaveCount(1)
        ->and(nonNull($rows->first())->analysis_type)->toBe(AnalysisType::PostRunSpeech)
        ->and(nonNull($rows->first())->status)->toBe(AnalysisStatus::Failed);
});

it('marks the speech row failed when the story line is missing', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    expect(nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value))->status)->toBe(AnalysisStatus::Failed);
});

it('no-ops when the speech is already Done (idempotent)', function (): void {
    $activity = seedActivityForJob();
    Analysis::factory()->done('preexisting')->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'discriminator' => null,
    ]);

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldNotReceive('generate');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    expect(nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value))->content)->toBe('preexisting');
});

it('fails the speech when generation throws', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andThrow(new RuntimeException('boom'));
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    expect(fn () => new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class)))
        ->toThrow(RuntimeException::class, 'boom');

    $speech = nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value));
    expect($speech->status)->toBe(AnalysisStatus::Failed)
        ->and($speech->error)->toBe('boom');
});

/**
 * Seed an activity for $user with a staged-Pending narration group at $startDate,
 * the chain shape a backfill produces (rows Pending, awaiting the chain).
 */
function pendingActivityGroup(User $user, string $startDate): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startDate),
        'distance' => 5000.0,
        'moving_time' => 1500,
        'elapsed_time' => 1500,
    ]);
    StoryLine::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => 'blazing',
    ]);
    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::factory()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
            'status' => AnalysisStatus::Pending,
        ]);
    }

    return $activity;
}

it('advances the chain to the next chronological Pending activity group on completion', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake');

    $user = User::factory()->create();
    $first = pendingActivityGroup($user, '2026-05-01 06:00:00');
    $next = pendingActivityGroup($user, '2026-05-03 06:00:00');
    mockSpeech();

    Bus::fake();
    new AnalyzeActivityJob($first->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $next->id);
});

it('advances the chain past an old run whose run-insight row never resolves', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake');

    $user = User::factory()->create();
    $first = pendingActivityGroup($user, '2026-05-01 06:00:00');
    legacyInsightRow($first, AnalysisStatus::Pending);
    $next = pendingActivityGroup($user, '2026-05-03 06:00:00');
    mockSpeech();

    Bus::fake();
    new AnalyzeActivityJob($first->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $next->id);
});

it('does not advance the chain when no later activity group is Pending', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake');

    $user = User::factory()->create();
    $only = pendingActivityGroup($user, '2026-05-01 06:00:00');
    mockSpeech();

    Bus::fake();
    new AnalyzeActivityJob($only->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('marks a speech the LLM answered as LLM-served, its call attributed to its own row', function (): void {
    $activity = seedActivityForJob();
    $seen = null;

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andReturnUsing(function () use (&$seen): string {
        $seen = app(NarratedAnalysis::class)->current();

        return 'nice run';
    });
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $speech = nonNull(activityRows($activity)->get(AnalysisType::PostRunSpeech->value));
    expect($seen)->toBe($speech->id)
        ->and($speech->served_by)->toBe(ServedBy::Llm);
});

it('fills the speech rule-based when it was filtered or rejected twice, minting no run-insight row', function (Throwable $thrown, AnalysisOrigin $reason): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andThrow($thrown);
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class), app(AnalysisRowState::class));

    $rows = activityRows($activity);
    expect($rows->keys()->all())->toBe([AnalysisType::PostRunSpeech->value])
        ->and(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->status)->toBe(AnalysisStatus::Done)
        ->and(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->served_by)->toBe(ServedBy::RuleBased)
        ->and(nonNull($rows->get(AnalysisType::PostRunSpeech->value))->rule_based_reason)->toBe($reason);
})->with([
    'content filter' => [new ContentFilterException('filtered'), AnalysisOrigin::ContentFilter],
    'rejected twice' => [new RejectedOutputException('rejected twice'), AnalysisOrigin::RejectedOutput],
]);
