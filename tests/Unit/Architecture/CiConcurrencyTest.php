<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const HEAD_SHA = 'github.event.workflow_run.head_sha';

/** @return array<mixed> */
function ciWorkflow(string $file): array
{
    return arrayOf(Yaml::parseFile(base_path(".github/workflows/{$file}")));
}

/** @return array<mixed> */
function deployJob(): array
{
    return arrayOf(data_get(ciWorkflow('release-deploy.yml'), 'jobs.deploy'));
}

/** @return array<mixed> */
function deployJobStep(string $name): array
{
    return arrayOf(collect(arrayOf(data_get(deployJob(), 'steps')))->firstWhere('name', $name) ?? []);
}

it('cancels a superseded CI run on every ref, main included', function (): void {
    expect(data_get(ciWorkflow('ci.yml'), 'concurrency'))->toBe([
        'group' => 'ci-${{ github.ref }}',
        'cancel-in-progress' => true,
    ]);
})->group('structure');

it('runs ci-gate and the frontend gate unless the run was cancelled, so a timed-out job still fails them', function (): void {
    expect(data_get(ciWorkflow('ci.yml'), 'jobs.ci-gate.if'))->toBe('${{ !cancelled() }}')
        ->and(data_get(ciWorkflow('ci-frontend.yml'), 'jobs.gate.if'))->toBe('${{ !cancelled() }}');
})->group('structure');

it('keeps the deploy out of the CI workflow', function (): void {
    $jobs = collect(arrayOf(data_get(ciWorkflow('ci.yml'), 'jobs')));

    expect($jobs->keys()->all())->not->toContain('deploy')->and($jobs->keys()->all())->not->toContain('notify')
        ->and($jobs->pluck('runs-on')->flatten()->all())->not->toContain('self-hosted');
})->group('structure');

it('deploys only after CI succeeds on a push to main', function (): void {
    $workflow = ciWorkflow('release-deploy.yml');

    expect(data_get(ciWorkflow('ci.yml'), 'name'))->toBe('CI')
        ->and(data_get($workflow, 'on'))->toBe(['workflow_run' => [
            'workflows' => ['CI'],
            'types' => ['completed'],
            'branches' => ['main'],
        ]])
        ->and(data_get(deployJob(), 'if'))->toBe("github.event.workflow_run.conclusion == 'success' && github.event.workflow_run.event == 'push'")
        ->and(deployJob())->not->toHaveKey('needs');
})->group('structure');

it('names each deploy run after the commit it deploys', function (): void {
    expect(data_get(ciWorkflow('release-deploy.yml'), 'run-name'))->toBe('${{ github.event.workflow_run.display_title }}');
})->group('structure');

it('never lets a newer deploy cancel one that has started', function (): void {
    expect(data_get(deployJob(), 'concurrency'))->toBe(['group' => 'deploy-prod', 'cancel-in-progress' => false])
        ->and(data_get(deployJob(), 'runs-on'))->toBe(['self-hosted', 'homelab']);
})->group('structure');

it('deploys the commit CI tested, never the default branch head', function (): void {
    $text = (string) file_get_contents(base_path('.github/workflows/release-deploy.yml'));
    $checkout = data_get(deployJob(), 'steps.0');

    expect($text)->not->toContain('github.sha')
        ->and(data_get($checkout, 'uses'))->toStartWith('actions/checkout@')
        ->and(data_get($checkout, 'with.ref'))->toBe('${{ '.HEAD_SHA.' }}')
        ->and(data_get(deployJobStep('Pull the image built on the hosted runner'), 'run'))->toContain('$APP_IMAGE:${{ '.HEAD_SHA.' }}')
        ->and(data_get(ciWorkflow('release-deploy.yml'), 'jobs.notify.with.message'))->toContain(HEAD_SHA);
})->group('structure');

it('skips a commit main has moved past before touching prod', function (): void {
    $tip = data_get(deployJob(), 'steps.1');

    expect(data_get($tip, 'id'))->toBe('tip')
        ->and(data_get($tip, 'run'))->toContain('git ls-remote origin refs/heads/main')
        ->toContain('if [ "$tip" = "${{ '.HEAD_SHA.' }}" ]; then');
})->group('structure');

it('rolls back a failed deploy that got past the newest-commit check', function (): void {
    expect(data_get(deployJobStep('Roll back on failure'), 'if'))->toBe("failure() && steps.tip.outputs.current == 'true'");
})->group('structure');
