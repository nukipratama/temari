<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

dataset('prod workflows', [
    '.github/workflows/release-deploy.yml',
    '.github/workflows/release-rollback.yml',
    '.github/workflows/data-backup.yml',
    '.github/workflows/data-restore-dry-run.yml',
    '.github/workflows/ops-disk.yml',
]);

it('never lets a prod compose up or run start, build or recreate mysql', function (string $workflow): void {
    preg_match_all(
        '/(?:\$COMPOSE|\$LIVE_COMPOSE|compose -f compose\.prod\.yaml)\s+(?:up|run|create)\b[^\n]*/',
        (string) File::get(base_path($workflow)),
        $commands,
    );

    expect($commands[0])->toBeArray();

    foreach ($commands[0] as $command) {
        expect($command)->toContain('--no-deps')->and($command)->not->toMatch('/\smysql\b/');
    }
})->with('prod workflows')->group('structure');

it('checks mysql is running before the deploy pulls anything, without bringing it up', function (): void {
    $steps = arrayOf(data_get(Yaml::parseFile(base_path('.github/workflows/release-deploy.yml')), 'jobs.deploy.steps'));
    $names = array_map(fn (mixed $step): mixed => data_get($step, 'name') ?? data_get($step, 'uses') ?? '', $steps);
    $guard = array_search('Require a running mysql (deploys never create or recreate it)', $names, true);
    $pull = array_search('Pull the image built on the hosted runner', $names, true);

    expect($guard)->toBeInt()
        ->and($pull)->toBeInt();

    $guardIndex = is_int($guard) ? $guard : throw new InvalidArgumentException('The mysql guard step is missing.');
    $pullIndex = is_int($pull) ? $pull : throw new InvalidArgumentException('The pull step is missing.');

    expect($guardIndex)->toBeLessThan($pullIndex)
        ->and(data_get($steps[$guardIndex], 'run'))->toContain('$COMPOSE ps -q mysql')
        ->toContain('exit 1')
        ->and(data_get($steps[$guardIndex], 'run'))->not->toMatch('/\$COMPOSE\s+(?:up|run|create|start|restart)\b/');
})->group('structure');

it('tags the prod mysql image with the version its Dockerfile builds, and never pulls it', function (): void {
    preg_match('/^FROM mysql:(\d+\.\d+\.\d+)@/m', (string) File::get(base_path('docker/mysql/Dockerfile')), $from);
    $prod = data_get(Yaml::parseFile(base_path('compose.prod.yaml')), 'services.mysql');
    $restore = data_get(Yaml::parseFile(base_path('deploy/restore-dry-run-compose.yml')), 'services.mysql_restore');

    expect($from)->toHaveKey(1)
        ->and(data_get($prod, 'image'))->toBe('temari/mysql:'.($from[1] ?? throw new InvalidArgumentException('The mysql Dockerfile names no version.')))
        ->and(data_get($prod, 'build.context'))->toBe('./docker/mysql')
        ->and(data_get($restore, 'image'))->toBe(data_get($prod, 'image'))
        ->and(data_get($restore, 'pull_policy'))->toBe('never');
})->group('structure');
