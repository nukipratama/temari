<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('re-caches the rolled-back app in the rollback workflow before recycling Horizon', function (): void {
    $names = array_column(Yaml::parseFile(base_path('.github/workflows/rollback.yml'))['jobs']['rollback']['steps'], 'run', 'name');
    $order = array_keys($names);

    expect($names['Optimize caches'] ?? null)->toBe('$COMPOSE exec -T app php artisan optimize')
        ->and(array_search('Optimize caches', $order, true))
        ->toBeGreaterThan(array_search('Roll app, horizon, scheduler, pulse onto rolled-back image', $order, true))
        ->toBeLessThan(array_search('Recycle Horizon workers', $order, true));
});

it('re-caches the rolled-back app when a failed deploy rolls itself back', function (): void {
    $steps = Yaml::parseFile(base_path('.github/workflows/deploy.yml'))['jobs']['deploy']['steps'];
    $rollback = collect($steps)->firstWhere('name', 'Roll back on failure')['run'];

    $roll = strpos($rollback, '$COMPOSE up -d --no-deps app horizon pulse scheduler');
    $optimize = strpos($rollback, '$COMPOSE exec -T app php artisan optimize');

    expect($roll)->toBeInt()
        ->and($optimize)->toBeInt()
        ->and($optimize)->toBeGreaterThan($roll)
        ->and($optimize)->toBeLessThan(strpos($rollback, 'horizon:terminate'));
});
