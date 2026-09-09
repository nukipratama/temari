<?php

declare(strict_types=1);

use App\Actions\AI\KickoffCatchUp;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

it('reports how many missing kickoff rows the sweep created', function (): void {
    $catchUp = Mockery::mock(KickoffCatchUp::class);
    $catchUp->shouldReceive('__invoke')->once()->andReturn(4);
    $this->app->instance(KickoffCatchUp::class, $catchUp);

    $this->artisan('ai:catch-up')
        ->expectsOutputToContain('Created 4 missing kickoff rows.')
        ->assertSuccessful();
});

it('meters anything the created rows later bill as scheduled work', function (): void {
    $catchUp = Mockery::mock(KickoffCatchUp::class);
    $catchUp->shouldReceive('__invoke')->once()->andReturn(0);
    $this->app->instance(KickoffCatchUp::class, $catchUp);

    $this->artisan('ai:catch-up')->assertSuccessful();

    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::Scheduled);
});
