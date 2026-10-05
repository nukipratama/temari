<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

dataset('prod workflows', [
    '.github/workflows/deploy.yml',
    '.github/workflows/rollback.yml',
    '.github/workflows/nightly-backup.yml',
    '.github/workflows/restore-dry-run.yml',
]);

it('never lets a prod compose up or run start, build or recreate mysql', function (string $workflow): void {
    preg_match_all(
        '/(?:\$COMPOSE|\$LIVE_COMPOSE|compose -f compose\.prod\.yaml)\s+(?:up|run|create)\b[^\n]*/',
        (string) File::get(base_path($workflow)),
        $commands,
    );

    expect($commands[0])->toBeArray();

    foreach ($commands[0] as $command) {
        expect($command)->toContain('--no-deps')->not->toMatch('/\smysql\b/');
    }
})->with('prod workflows')->group('structure');

it('checks mysql is running before the deploy pulls anything, without bringing it up', function (): void {
    $steps = Yaml::parseFile(base_path('.github/workflows/deploy.yml'))['jobs']['deploy']['steps'];
    $names = array_map(fn (array $step): string => $step['name'] ?? $step['uses'] ?? '', $steps);
    $guard = array_search('Require a running mysql (deploys never create or recreate it)', $names, true);
    $pull = array_search('Pull the image built on the hosted runner', $names, true);

    expect($guard)->toBeInt()
        ->and($pull)->toBeInt()
        ->and($guard)->toBeLessThan($pull)
        ->and($steps[$guard]['run'])->toContain('$COMPOSE ps -q mysql')
        ->toContain('exit 1')
        ->not->toMatch('/\$COMPOSE\s+(?:up|run|create|start|restart)\b/');
})->group('structure');

it('tags the prod mysql image with the version its Dockerfile builds, and never pulls it', function (): void {
    preg_match('/^FROM mysql:(\d+\.\d+)@/m', (string) File::get(base_path('docker/mysql/Dockerfile')), $from);
    $prod = Yaml::parseFile(base_path('compose.prod.yaml'))['services']['mysql'];
    $restore = Yaml::parseFile(base_path('deploy/restore-dry-run-compose.yml'))['services']['mysql_restore'];

    expect($from)->toHaveKey(1)
        ->and($prod['image'])->toBe("temari/mysql:{$from[1]}")
        ->and($prod['build']['context'])->toBe('./docker/mysql')
        ->and($restore['image'])->toBe($prod['image'])
        ->and($restore['pull_policy'])->toBe('never');
})->group('structure');
