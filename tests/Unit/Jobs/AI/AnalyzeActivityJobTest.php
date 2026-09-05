<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use App\Exceptions\AI\UnavailableException;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Binds a stub RunInsightNarrator so the activity job's LLM insight call is
 * deterministic; without it the job would hit the real Azure client.
 *
 * @param  list<array{anchor: string, text: string, value: string|null, delta: string|null}>  $claims
 */
function mockInsightNarrator(array $claims): void
{
    $mock = Mockery::mock(RunInsightNarrator::class);
    $mock->shouldReceive('generate')->andReturn(['claims' => $claims]);
    app()->instance(RunInsightNarrator::class, $mock);
}

/** A single deterministic claim, so tests don't need to spell out the shape every time. */
function sampleClaim(string $text = 'sample claim'): array
{
    return ['anchor' => 'metric:decoupling', 'text' => $text, 'value' => null, 'delta' => null];
}

function seedActivityForJob(): Activity
{
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);
    StoryLine::factory()->create([
        'activity_id' => $activity->id,
        'user_id' => $user->id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => 'blazing',
    ]);

    return $activity;
}

it('writes speech + insight rows Done from one job run', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    // The speech is told the mood and nothing else about the run: the insight
    // claims are the other lens' material, not its own.
    $speechMock->shouldReceive('generate')
        ->withArgs(fn ($a, $d, $mood): bool => $mood === 'blazing')
        ->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);
    mockInsightNarrator([sampleClaim('tech text')]);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value);

    expect($rows)->toHaveCount(2)
        ->and($rows[AnalysisType::PostRunSpeech->value]->content)->toBe('nice run')
        ->and(json_decode((string) $rows[AnalysisType::RunInsight->value]->content, true))
        ->toBe([sampleClaim('tech text')]);

    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Done);
    }
});

it('stamps every group row with the activity material fingerprint at generation', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);
    mockInsightNarrator([sampleClaim()]);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class));

    $expected = MaterialFingerprint::forActivity($activity->fresh(['detail']));
    $rows = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('content_fingerprint')->unique()->all())->toBe([$expected]);
});

it('reverts group rows to Pending without billing when generation is paused', function (): void {
    // Azure unset -> generationPaused true. No narrator is mocked, so if the guard
    // failed the job would hit the real client; instead it must return early.
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

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe([AnalysisStatus::Pending])
        ->and($rows->pluck('attempts')->unique()->all())->toBe([0]);
});

// An unavailable model used to degrade the insights to deterministic template
// copy. It no longer does: a template presented as narration is a lie the user
// cannot see through, so the group fails honestly and the UI offers "Try again".
it('fails the whole group rather than templating run-insight when the LLM is unavailable', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldNotReceive('generate');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    $insightMock = Mockery::mock(RunInsightNarrator::class);
    $insightMock->shouldReceive('generate')->andThrow(new UnavailableException('llm down'));
    app()->instance(RunInsightNarrator::class, $insightMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed)
            ->and($row->content)->toBeNull();
    }
});

it('reuses the Done insight row instead of re-billing RunInsightNarrator on a story-only re-dispatch', function (): void {
    $activity = seedActivityForJob();

    // The insight row is already Done with known content; only PostRunSpeech is Pending.
    Analysis::factory()->done(json_encode([sampleClaim('stored claim')], JSON_THROW_ON_ERROR))->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::RunInsight,
        'discriminator' => null,
    ]);

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andReturn('new story');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    // The insight LLM must NOT be called: the Done row is reused verbatim.
    $insightMock = Mockery::mock(RunInsightNarrator::class);
    $insightMock->shouldNotReceive('generate');
    app()->instance(RunInsightNarrator::class, $insightMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value);

    expect($rows[AnalysisType::PostRunSpeech->value]->content)->toBe('new story')
        ->and(json_decode((string) $rows[AnalysisType::RunInsight->value]->content, true))
        ->toBe([sampleClaim('stored claim')]);
});

it('marks all 2 rows failed when the activity is missing', function (): void {
    new AnalyzeActivityJob(99999)->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', 99999)->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed);
    }
});

it('marks all rows failed when the story line is missing', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    // No StoryLine created — speech narrator can't run.

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed);
    }
});

it('no-ops when all rows already Done (idempotent)', function (): void {
    $activity = seedActivityForJob();

    foreach ([
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsight,
    ] as $type) {
        Analysis::factory()->done('preexisting')->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
        ]);
    }

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldNotReceive('generate');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    foreach ($rows as $row) {
        expect($row->content)->toBe('preexisting');
    }
});

it('rethrows non-UnavailableException so Laravel can retry the whole group', function (): void {
    $activity = seedActivityForJob();

    $insightMock = Mockery::mock(RunInsightNarrator::class);
    $insightMock->shouldReceive('generate')->andReturn(['claims' => [sampleClaim()]]);
    app()->instance(RunInsightNarrator::class, $insightMock);

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andThrow(new RuntimeException('boom'));
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    expect(fn () => new AnalyzeActivityJob($activity->id)->handle(app(AnalysisService::class)))
        ->toThrow(RuntimeException::class, 'boom');

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed)
            ->and($row->error)->toBe('boom');
    }
});

it('shared retry config: tries=3, backoff=[10, 60]', function (): void {
    $job = new AnalyzeActivityJob(1);
    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60]);
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

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);
    mockInsightNarrator([sampleClaim()]);

    Bus::fake();
    new AnalyzeActivityJob($first->id)->handle(app(AnalysisService::class));

    // The next chronological activity's group is dispatched as the chain link.
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $next->id,
    );
});

it('does not advance the chain when no later activity group is Pending', function (): void {
    config()->set('azure_openai.uri', 'https://x.openai.azure.com/x');
    config()->set('azure_openai.api_key', 'fake');

    $user = User::factory()->create();
    $only = pendingActivityGroup($user, '2026-05-01 06:00:00');

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);
    mockInsightNarrator([sampleClaim()]);

    Bus::fake();
    new AnalyzeActivityJob($only->id)->handle(app(AnalysisService::class));

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});
