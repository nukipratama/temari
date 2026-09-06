<?php

declare(strict_types=1);

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Jobs\AI\KickoffRecapsJob;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

it('runs the weekly and monthly kickoff for its own user, attributed to the ingest cascade', function (): void {
    $origin = app(NarrationOrigin::class);
    $seen = [];

    $weekly = Mockery::mock(KickoffWeeklyRecaps::class);
    $weekly->shouldReceive('__invoke')->once()
        ->andReturnUsing(function (?int $userId) use (&$seen, $origin): array {
            $seen['weekly'] = [$userId, $origin->current()];

            return ['dispatched' => 0, 'rule_based' => 0];
        });

    $monthly = Mockery::mock(KickoffMonthlyRecaps::class);
    $monthly->shouldReceive('__invoke')->once()
        ->andReturnUsing(function (?int $userId) use (&$seen, $origin): array {
            $seen['monthly'] = [$userId, $origin->current()];

            return ['dispatched' => 0, 'rule_based' => 0];
        });

    new KickoffRecapsJob(42)->handle($weekly, $monthly);

    expect($seen['weekly'])->toBe([42, AnalysisOrigin::Ingest])
        ->and($seen['monthly'])->toBe([42, AnalysisOrigin::Ingest]);
});
