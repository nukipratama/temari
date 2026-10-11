<?php

declare(strict_types=1);

use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

it('bumps the volume-floor cache generation in the durable store', function (): void {
    $this->artisan('plan:forget-volume-floors')->assertSuccessful();
    $this->artisan('plan:forget-volume-floors')->assertSuccessful();

    expect((int) Cache::store('durable')->get(TrainingBaseline::VOLUME_FLOOR_GENERATION_KEY))->toBe(2);
});

it('runs as part of artisan optimize, which deploy and rollback both call', function (): void {
    expect(ServiceProvider::$optimizeCommands)->toContain('plan:forget-volume-floors');
});

it('succeeds with a warning when the durable store is unreachable, so a deploy never fails on it', function (): void {
    config(['cache.stores.durable.connection' => 'unreachable']);
    Cache::forgetDriver('durable');

    $this->artisan('plan:forget-volume-floors')
        ->expectsOutputToContain('Could not bump the volume-floor cache generation')
        ->assertSuccessful();
});
