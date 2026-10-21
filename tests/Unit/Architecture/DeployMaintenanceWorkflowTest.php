<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return array<mixed> */
function deploySteps(): array
{
    return arrayOf(data_get(Yaml::parseFile(base_path('.github/workflows/release-deploy.yml')), 'jobs.deploy.steps'));
}

/** @return array<mixed> */
function deployStep(string $name): array
{
    foreach (deploySteps() as $step) {
        if (data_get($step, 'name') === $name) {
            return arrayOf($step);
        }
    }

    expect(false)->toBeTrue("Missing deploy step: {$name}");

    return [];
}

function deployStepIndex(string $name): int
{
    $index = array_search($name, array_column(deploySteps(), 'name'), true);

    return is_int($index) ? $index : throw new InvalidArgumentException("Missing deploy step: {$name}");
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
    expect(deployStepIndex('Enter maintenance for pending migrations'))
        ->toBeLessThan(deployStepIndex('Migrate (one-shot, on the new image)'))
        ->and(deployStepIndex('Healthcheck'))
        ->toBeLessThan(deployStepIndex('Leave deploy-owned maintenance'))
        ->and(deployStepIndex('Smoke test'))
        ->toBeLessThan(deployStepIndex('Leave deploy-owned maintenance'))
        ->and(deployStepIndex('Roll Pulse daemon (deploy green)'))
        ->toBeLessThan(deployStepIndex('Leave deploy-owned maintenance'));
});

it('keeps deploy-owned maintenance active when the migration path fails', function (): void {
    $rollback = deployStep('Roll back on failure')['run'];

    expect($rollback)->toContain('MIGRATIONS_APPLIED')
        ->toContain('leaving maintenance active')
        ->and($rollback)->not->toContain('php artisan up');
});

it('uses shallow readiness for app lifecycle checks while retaining deep health separately', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/release-deploy.yml'));
    $compose = file_get_contents(base_path('compose.prod.yaml'));
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($workflow)->toContain('http://127.0.0.1:7001/ready')
        ->and($compose)->toContain('http://127.0.0.1:7001/ready')
        ->and($dockerfile)->toContain('http://127.0.0.1:7001/ready');
});

it('gives the quiesced scheduler a grace period longer than its slowest scheduled run', function (): void {
    expect(data_get(Yaml::parseFile(base_path('compose.prod.yaml')), 'services.scheduler.stop_grace_period'))->toBe('120s')
        ->and(deployStep('Quiesce scheduler + horizon before the roll')['run'])->not->toMatch('/\s-t\s|--timeout/');
});
