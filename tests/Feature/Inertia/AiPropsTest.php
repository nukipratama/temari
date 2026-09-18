<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Inertia\AiProps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function aiPropsFor(?User $user): array
{
    return app(AiProps::class)->forUser($user);
}

/**
 * A run still owed a hydration: a summary-only row under the detail-fetch
 * retry cap, matching Activity::awaitingHydration().
 */
function unhydratedRunFor(User $user, string $startedAt): Activity
{
    $activity = Activity::factory()->for($user)->create([
        'ingest_state' => IngestState::Summary,
        'analyzed_at' => null,
    ]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($startedAt)]);

    return $activity;
}

it('keeps the pause signal a closure so a partial reload can skip it', function (): void {
    expect(aiPropsFor(User::factory()->create())['aiPaused'])->toBeInstanceOf(Closure::class);
});

it('never asks the pipeline about a guest', function (): void {
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldNotReceive('generationPaused');
    app()->instance(AnalysisService::class, $analyses);

    expect((aiPropsFor(null)['aiPaused'])())->toBeFalse();
});

it('shares the pause fact for a signed-in user', function (bool $paused): void {
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldReceive('generationPaused')->andReturn($paused);
    app()->instance(AnalysisService::class, $analyses);

    expect((aiPropsFor(User::factory()->create())['aiPaused'])())->toBe($paused);
})->with([
    'generating' => [false],
    'paused' => [true],
]);

function fakeGenerationPaused(bool $paused): void
{
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldReceive('generationPaused')->andReturn($paused);
    app()->instance(AnalysisService::class, $analyses);
}

it('keeps the catching-up signal a closure so a partial reload can skip it', function (): void {
    expect(aiPropsFor(User::factory()->create())['aiCatchingUp'])->toBeInstanceOf(Closure::class);
});

it('never asks the pipeline about a guest for the catching-up signal', function (): void {
    $analyses = Mockery::mock(AnalysisService::class);
    $analyses->shouldNotReceive('generationPaused');
    app()->instance(AnalysisService::class, $analyses);

    expect((aiPropsFor(null)['aiCatchingUp'])())->toBeFalse();
});

it('is false while generation is globally paused, regardless of pending narration', function (): void {
    fakeGenerationPaused(true);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});

it('is false when every activity is fully narrated', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::factory()->done()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
        ]);
    }

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});

it('is true when an activity has a queued narration row', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeTrue();
});

it('is true when a failed narration row is still under the self-heal retry budget', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->failed()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeTrue();
});

it('is false when a narration row is dead-lettered and needs a manual re-arm', function (): void {
    fakeGenerationPaused(false);

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->failed()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});

it('ignores another user\'s pending narration', function (): void {
    fakeGenerationPaused(false);

    $otherUser = User::factory()->create();
    $activity = Activity::factory()->for($otherUser)->create();
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    $user = User::factory()->create();
    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();
});

it('is true for a just-connected athlete whose backlog is still hydrating, even with no queued rows', function (): void {
    fakeGenerationPaused(false);
    Carbon::setTestNow('2026-09-19 09:00:00');

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()]);
    unhydratedRunFor($user, '2026-09-01 06:00:00');

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeTrue();

    Carbon::setTestNow();
});

it('is false once hydration completes and narration has drained', function (): void {
    fakeGenerationPaused(false);
    Carbon::setTestNow('2026-09-19 09:00:00');

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-09-01 06:00:00')]);
    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::factory()->done()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
        ]);
    }

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();

    Carbon::setTestNow();
});

it('is false for an athlete with only a deferred recap pending row', function (): void {
    fakeGenerationPaused(false);
    Carbon::setTestNow('2026-09-19 09:00:00');

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::parse('2026-09-01 06:00:00')]);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-09-01 06:00:00')]);
    $snapshot = WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::now()->endOfWeek()->toDateString()]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();

    Carbon::setTestNow();
});

it('stays false for a long-connected athlete outside the grace window despite a stuck backlog', function (): void {
    fakeGenerationPaused(false);
    Carbon::setTestNow('2026-09-19 09:00:00');

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::parse('2026-09-01 06:00:00')]);
    unhydratedRunFor($user, '2026-08-20 06:00:00');

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeFalse();

    Carbon::setTestNow();
});

it('still catches a plain queued row for a long-connected athlete outside the grace window', function (): void {
    fakeGenerationPaused(false);
    Carbon::setTestNow('2026-09-19 09:00:00');

    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::parse('2026-09-01 06:00:00')]);
    $activity = Activity::factory()->for($user)->create();
    Analysis::factory()->queued()->create([
        'subject_type' => Activity::class,
        'subject_id' => $activity->id,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    expect((aiPropsFor($user)['aiCatchingUp'])())->toBeTrue();

    Carbon::setTestNow();
});
