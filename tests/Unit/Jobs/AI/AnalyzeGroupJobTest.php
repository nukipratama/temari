<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a missing generation token on a legacy serialized job', function (): void {
    $job = new AnalyzeActivityJob(1, null, 'before-deploy-token');
    unset($job->generationToken);

    $restored = unserialize(serialize($job), ['allowed_classes' => true]);

    expect($restored)->toBeInstanceOf(AnalyzeActivityJob::class);
    assert($restored instanceof AnalyzeActivityJob);
    expect($restored->generationToken)->toBeNull();
});

it('does not run a same-generation duplicate while its rows are Processing', function (): void {
    $activity = Activity::factory()->for(User::factory())->analyzed()->create();
    $token = 'active-generation';

    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::factory()->queued()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
            'generation_token' => $token,
            'status' => AnalysisStatus::Processing,
            'attempts' => 1,
        ]);
    }

    $job = new AnalyzeActivityJob($activity->id, null, $token);
    $job->handle(app(AnalysisService::class));

    $rows = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Processing))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => $row->attempts === 1))->toBeTrue()
        ->and($rows->every(fn (Analysis $row): bool => $row->generation_token === $token))->toBeTrue();
});
