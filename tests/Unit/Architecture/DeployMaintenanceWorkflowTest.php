<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

function deploySteps(): array
{
    return Yaml::parseFile(base_path('.github/workflows/ci.yml'))['jobs']['deploy']['steps'];
}

function deployStep(string $name): array
{
    foreach (deploySteps() as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    expect(false)->toBeTrue("Missing deploy step: {$name}");

    return [];
}

it('uses maintenance only for pending migrations and preserves owner maintenance', function (): void {
    $record = deployStep('Record pre-deploy maintenance state')['run'];
    $enter = deployStep('Enter maintenance for pending migrations')['run'];
    $leave = deployStep('Leave deploy-owned maintenance')['run'];

    expect($record)->toContain('MAINTENANCE_WAS_ACTIVE')
        ->toContain('app()->isDownForMaintenance()')
        ->and($enter)->toContain('MIGRATIONS_APPLIED')
        ->toContain('MAINTENANCE_WAS_ACTIVE')
        ->toContain('DEPLOY_ENABLED_MAINTENANCE')
        ->toContain('php artisan down')
        ->and($leave)->toContain('DEPLOY_ENABLED_MAINTENANCE')
        ->toContain('php artisan up');
});

it('enters maintenance before migrating and lifts it only after health and smoke checks', function (): void {
    $names = array_column(deploySteps(), 'name');

    expect(array_search('Enter maintenance for pending migrations', $names, true))
        ->toBeLessThan(array_search('Migrate (one-shot, on the new image)', $names, true))
        ->and(array_search('Healthcheck', $names, true))
        ->toBeLessThan(array_search('Leave deploy-owned maintenance', $names, true))
        ->and(array_search('Smoke test', $names, true))
        ->toBeLessThan(array_search('Leave deploy-owned maintenance', $names, true))
        ->and(array_search('Roll Pulse daemon (deploy green)', $names, true))
        ->toBeLessThan(array_search('Leave deploy-owned maintenance', $names, true));
});

it('keeps deploy-owned maintenance active when the migration path fails', function (): void {
    $rollback = deployStep('Roll back on failure')['run'];

    expect($rollback)->toContain('MIGRATIONS_APPLIED')
        ->toContain('leaving maintenance active')
        ->not->toContain('php artisan up');
});

it('uses shallow readiness for app lifecycle checks while retaining deep health separately', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
    $compose = file_get_contents(base_path('compose.prod.yaml'));
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($workflow)->toContain('http://127.0.0.1:7001/ready')
        ->and($compose)->toContain('http://127.0.0.1:7001/ready')
        ->and($dockerfile)->toContain('http://127.0.0.1:7001/ready');
});
