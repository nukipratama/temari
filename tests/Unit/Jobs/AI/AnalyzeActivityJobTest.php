<?php

declare(strict_types=1);

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
        'mood' => 'glow',
    ]);

    return $activity;
}

it('writes speech + 3 insight rows Done from one job run', function (): void {
    $activity = seedActivityForJob();

    $speechMock = Mockery::mock(PostRunSpeechNarrator::class);
    $speechMock->shouldReceive('generate')->andReturn('nice run');
    app()->instance(PostRunSpeechNarrator::class, $speechMock);

    $insightMock = Mockery::mock(RunInsightNarrator::class);
    $insightMock->shouldReceive('generate')->andReturn([
        'technical' => 'T-narrative',
        'splits' => 'S-narrative',
        'zones' => 'Z-narrative',
    ]);
    app()->instance(RunInsightNarrator::class, $insightMock);

    (new AnalyzeActivityJob($activity->id))->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->get()
        ->keyBy(fn (Analysis $r): string => $r->analysis_type->value);

    expect($rows)->toHaveCount(4)
        ->and($rows[AnalysisType::PostRunSpeech->value]->content)->toBe('nice run')
        ->and($rows[AnalysisType::RunInsightTechnical->value]->content)->toBe('T-narrative')
        ->and($rows[AnalysisType::RunInsightSplits->value]->content)->toBe('S-narrative')
        ->and($rows[AnalysisType::RunInsightZones->value]->content)->toBe('Z-narrative');

    foreach ($rows as $row) {
        expect($row->status)->toBe(AnalysisStatus::Done);
    }
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
