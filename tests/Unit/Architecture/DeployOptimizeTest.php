<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('optimizes after the deploy rolls app and workers, so the volume-floor generation bump lands after the old code stops', function (): void {
    $steps = Yaml::parseFile(base_path('.github/workflows/deploy.yml'))['jobs']['deploy']['steps'];
    $names = array_column($steps, 'run', 'name');
    $order = array_keys($names);

    expect($names['Optimize caches'] ?? null)->toBe('$COMPOSE exec -T app php artisan optimize')
        ->and(array_search('Optimize caches', $order, true))
        ->toBeGreaterThan(array_search('Roll app + workers onto the new image', $order, true))
        ->toBeLessThan(array_search('Healthcheck', $order, true));
});
