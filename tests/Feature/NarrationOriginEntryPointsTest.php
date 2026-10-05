<?php

declare(strict_types=1);

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Jobs\Run\ReconcilePlanJob;
use App\Jobs\Run\RegeneratePlanJob;
use App\Jobs\Strava\CleanupDeletedActivityJob;
use App\Jobs\Strava\IngestActivityJob;
use App\Jobs\Strava\ResyncActivityJob;
use App\Enums\PlanRegenerationReason;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function originAfter(Closure $run): AnalysisOrigin
{
    app()->forgetScopedInstances();
    $run();

    return app(NarrationOrigin::class)->current();
}

dataset('queued entry points that can reach a narrator', [
    'ingest' => [fn () => new IngestActivityJob(0), AnalysisOrigin::Ingest],
    'webhook resync' => [fn () => new ResyncActivityJob(0), AnalysisOrigin::Ingest],
    'deleted-activity cleanup' => [fn () => new CleanupDeletedActivityJob(0, 0), AnalysisOrigin::Ingest],
    'plan reconciliation' => [fn () => new ReconcilePlanJob(0), AnalysisOrigin::Ingest],
    'training history recalibration' => [fn () => new RecalibrateTrainingHistoryJob(0), AnalysisOrigin::Ingest],
    'queued plan regeneration' => [fn () => new RegeneratePlanJob(0, PlanRegenerationReason::Manual), AnalysisOrigin::User],
]);

dataset('console entry points that can reach a narrator', [
    'ai:daily-briefing' => ['ai:daily-briefing', [], AnalysisOrigin::Scheduled],
    'ai:weekly-recap' => ['ai:weekly-recap', [], AnalysisOrigin::Scheduled],
    'ai:weekly-profile' => ['ai:weekly-profile', [], AnalysisOrigin::Scheduled],
    'ai:trend-read' => ['ai:trend-read', ['range' => '7d'], AnalysisOrigin::Scheduled],
    'ai:catch-up' => ['ai:catch-up', [], AnalysisOrigin::Scheduled],
    'ai:self-heal' => ['ai:self-heal', [], AnalysisOrigin::Recovery],
    'ai:recover' => ['ai:recover', [], AnalysisOrigin::Recovery],
    'plan:regenerate' => ['plan:regenerate', [], AnalysisOrigin::Scheduled],
    'plan:score-compliance' => ['plan:score-compliance', [], AnalysisOrigin::Scheduled],
    'strava:resync-activity' => ['strava:resync-activity', ['activity' => 0], AnalysisOrigin::Recovery],
    'run:rebuild-splits' => ['run:rebuild-splits', ['--skip-fetch' => true], AnalysisOrigin::Recovery],
]);

it('declares an origin before a queued job can reach a narrator', function (Closure $makeJob, AnalysisOrigin $expected): void {
    $job = $makeJob();

    $origin = originAfter(fn () => app()->call([$job, 'handle']));

    expect($origin)->toBe($expected)->not->toBe(AnalysisOrigin::Unknown);
})->with('queued entry points that can reach a narrator');

it('declares an origin before a console command can reach a narrator', function (string $command, array $arguments, AnalysisOrigin $expected): void {
    $origin = originAfter(fn () => Artisan::call($command, $arguments));

    expect($origin)->toBe($expected)->not->toBe(AnalysisOrigin::Unknown);
})->with('console entry points that can reach a narrator');
