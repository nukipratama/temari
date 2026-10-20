<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @param array<int, int|string> $order */
function rollbackStepIndex(array $order, string $name): int
{
    $index = array_search($name, $order, true);

    return is_int($index) ? $index : throw new InvalidArgumentException("Missing rollback step: {$name}");
}

it('re-caches the rolled-back app in the rollback workflow before recycling Horizon', function (): void {
    $names = array_column(arrayOf(data_get(Yaml::parseFile(base_path('.github/workflows/rollback.yml')), 'jobs.rollback.steps')), 'run', 'name');
    $order = array_keys($names);

    expect($names['Optimize caches'] ?? null)->toBe('$COMPOSE exec -T app php artisan optimize')
        ->and(rollbackStepIndex($order, 'Optimize caches'))
        ->toBeGreaterThan(rollbackStepIndex($order, 'Roll app, horizon, scheduler, pulse onto rolled-back image'))
        ->toBeLessThan(rollbackStepIndex($order, 'Recycle Horizon workers'));
});

it('re-caches the rolled-back app when a failed deploy rolls itself back', function (): void {
    $steps = arrayOf(data_get(Yaml::parseFile(base_path('.github/workflows/deploy.yml')), 'jobs.deploy.steps'));
    $run = data_get(collect($steps)->firstWhere('name', 'Roll back on failure'), 'run');
    $rollback = is_string($run) ? $run : throw new InvalidArgumentException('The rollback step run block is not a string.');

    $roll = strpos($rollback, '$COMPOSE up -d --no-deps app horizon pulse scheduler');
    $optimize = strpos($rollback, '$COMPOSE exec -T app php artisan optimize');

    expect($roll)->toBeInt()
        ->and($optimize)->toBeInt();

    $rollAt = is_int($roll) ? $roll : throw new InvalidArgumentException('The rollback step does not roll the app.');
    $optimizeAt = is_int($optimize) ? $optimize : throw new InvalidArgumentException('The rollback step does not optimize.');
    $terminateAt = strpos($rollback, 'horizon:terminate');

    expect($optimizeAt)->toBeGreaterThan($rollAt)
        ->toBeLessThan(is_int($terminateAt) ? $terminateAt : throw new InvalidArgumentException('The rollback step does not terminate Horizon.'));
});
