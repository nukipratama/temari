<?php

declare(strict_types=1);

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
use App\Services\AI\Narrators\PostRunSpeechNarrator;
use App\Services\AI\Narrators\RunInsightNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Binds a stub RunInsightNarrator so the activity job's LLM insight call is
 * deterministic; without it the job would hit the real Azure client.
 *
 * @param  array{technical: string, splits: string, zones: string}  $insights
 */
function mockInsightNarrator(array $insights): void
{
    $mock = Mockery::mock(RunInsightNarrator::class);
    $mock->shouldReceive('generate')->andReturn($insights);
    app()->instance(RunInsightNarrator::class, $mock);
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
        'mood' => 'nyala',
    ]);

    return $activity;
}

it('writes speech + 3 insight rows Done from one job run', function (): void {
    $activity = seedActivityForJob();

    $insights = ['technical' => 'tech text', 'splits' => 'splits text', 'zones' => 'zones text'];

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    // The generated insight triplet flows into the speech narrator's 4th arg.
    $speechMock->shouldReceive('generate')
        ->withArgs(fn ($a, $d, $mood, $passed): bool => $passed === $insights)
        ->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);
    mockInsightNarrator($insights);

    (new AnalyzeActivityJob($activity->id))->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value);

    expect($rows)->toHaveCount(4)
        ->and($rows[AnalysisType::PostRunSpeech->value]->content)->toBe('nice run')
        ->and($rows[AnalysisType::RunInsightTechnical->value]->content)->toBe('tech text')
        ->and($rows[AnalysisType::RunInsightSplits->value]->content)->toBe('splits text')
        ->and($rows[AnalysisType::RunInsightZones->value]->content)->toBe('zones text');

    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Done);
    }
});

it('degrades run-insight to rule-based content when the LLM is unavailable', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    // LLM run-insight throws: the job should fall back to the rule-based builder,
    // not fail the whole group.
    $insightMock = Mockery::mock(RunInsightNarrator::class);
    $insightMock->shouldReceive('generate')->andThrow(new UnavailableException('llm down'));
    app()->instance(RunInsightNarrator::class, $insightMock);

    (new AnalyzeActivityJob($activity->id))->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value);

    foreach ([AnalysisType::RunInsightTechnical, AnalysisType::RunInsightSplits, AnalysisType::RunInsightZones] as $type) {
        expect($rows[$type->value]->status)->toBe(AnalysisStatus::Done)
            ->and($rows[$type->value]->content)->not->toBeEmpty();
    }
});

it('reuses Done insight rows instead of re-billing RunInsightNarrator on a cerita-only re-dispatch', function (): void {
    $activity = seedActivityForJob();

    // The 3 insight rows are already Done with known content; only PostRunSpeech is Pending.
    $doneContent = [
        AnalysisType::RunInsightTechnical->value => 'stored tech',
        AnalysisType::RunInsightSplits->value => 'stored splits',
        AnalysisType::RunInsightZones->value => 'stored zones',
    ];
    foreach ($doneContent as $type => $content) {
        Analysis::factory()->done($content)->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
        ]);
    }

    $expectedInsights = [
        'technical' => 'stored tech',
        'splits' => 'stored splits',
        'zones' => 'stored zones',
    ];

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')
        ->withArgs(fn ($a, $d, $mood, $passed): bool => $passed === $expectedInsights)
        ->andReturn('cerita baru');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    // The insight LLM must NOT be called: the Done rows are reused verbatim.
    $insightMock = Mockery::mock(RunInsightNarrator::class);
    $insightMock->shouldNotReceive('generate');
    app()->instance(RunInsightNarrator::class, $insightMock);

    (new AnalyzeActivityJob($activity->id))->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value);

    expect($rows[AnalysisType::PostRunSpeech->value]->content)->toBe('cerita baru')
        ->and($rows[AnalysisType::RunInsightTechnical->value]->content)->toBe('stored tech')
        ->and($rows[AnalysisType::RunInsightSplits->value]->content)->toBe('stored splits')
        ->and($rows[AnalysisType::RunInsightZones->value]->content)->toBe('stored zones');
});

it('marks all 4 rows failed when the activity is missing', function (): void {
    (new AnalyzeActivityJob(99999))->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', 99999)->get();
    expect($rows)->toHaveCount(4);
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed);
    }
});

it('marks all rows failed when the story line is missing', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    // No StoryLine created — speech narrator can't run.

    (new AnalyzeActivityJob($activity->id))->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Failed);
    }
});

it('no-ops when all rows already Done (idempotent)', function (): void {
    $activity = seedActivityForJob();

    foreach ([
        AnalysisType::PostRunSpeech,
        AnalysisType::RunInsightTechnical,
        AnalysisType::RunInsightSplits,
        AnalysisType::RunInsightZones,
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

    (new AnalyzeActivityJob($activity->id))->handle(app(AnalysisService::class));

    $rows = Analysis::query()->where('subject_id', $activity->id)->get();
    foreach ($rows as $row) {
        expect($row->content)->toBe('preexisting');
    }
});

it('rethrows non-UnavailableException so Laravel can retry the whole group', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andThrow(new RuntimeException('boom'));
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    expect(fn () => (new AnalyzeActivityJob($activity->id))->handle(app(AnalysisService::class)))
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
        'mood' => 'nyala',
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
    mockInsightNarrator(['technical' => 't', 'splits' => 's', 'zones' => 'z']);

    Illuminate\Support\Facades\Bus::fake();
    (new AnalyzeActivityJob($first->id))->handle(app(AnalysisService::class));

    // The next chronological activity's group is dispatched as the chain link.
    Illuminate\Support\Facades\Bus::assertDispatched(
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
    mockInsightNarrator(['technical' => 't', 'splits' => 's', 'zones' => 'z']);

    Illuminate\Support\Facades\Bus::fake();
    (new AnalyzeActivityJob($only->id))->handle(app(AnalysisService::class));

    Illuminate\Support\Facades\Bus::assertNotDispatched(AnalyzeActivityJob::class);
});
