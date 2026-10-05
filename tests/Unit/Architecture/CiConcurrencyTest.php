<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const HEAD_SHA = 'github.event.workflow_run.head_sha';

function ciWorkflow(string $file): array
{
    return Yaml::parseFile(base_path(".github/workflows/{$file}"));
}

/** @return array<string, mixed> */
function deployJob(): array
{
    return ciWorkflow('deploy.yml')['jobs']['deploy'];
}

/** @return array<string, mixed> */
function deployJobStep(string $name): array
{
    return collect(deployJob()['steps'])->firstWhere('name', $name) ?? [];
}

it('cancels a superseded CI run on every ref, main included', function (): void {
    expect(ciWorkflow('ci.yml')['concurrency'])->toBe([
        'group' => 'ci-${{ github.ref }}',
        'cancel-in-progress' => true,
    ]);
})->group('structure');

it('runs ci-gate and both suite gates unless the run was cancelled, so a timed-out job still fails them', function (): void {
    expect(ciWorkflow('ci.yml')['jobs']['ci-gate']['if'])->toBe('${{ !cancelled() }}')
        ->and(ciWorkflow('backend-ci.yml')['jobs']['gate']['if'])->toBe('${{ !cancelled() }}')
        ->and(ciWorkflow('frontend-ci.yml')['jobs']['gate']['if'])->toBe('${{ !cancelled() }}');
})->group('structure');

it('keeps the deploy out of the CI workflow', function (): void {
    $jobs = collect(ciWorkflow('ci.yml')['jobs']);

    expect($jobs->keys()->all())->not->toContain('deploy')->not->toContain('notify')
        ->and($jobs->pluck('runs-on')->flatten()->all())->not->toContain('self-hosted');
})->group('structure');

it('deploys only after CI succeeds on a push to main', function (): void {
    $workflow = ciWorkflow('deploy.yml');

    expect(ciWorkflow('ci.yml')['name'])->toBe('CI')
        ->and($workflow['on'])->toBe(['workflow_run' => [
            'workflows' => ['CI'],
            'types' => ['completed'],
            'branches' => ['main'],
        ]])
        ->and(deployJob()['if'])->toBe("github.event.workflow_run.conclusion == 'success' && github.event.workflow_run.event == 'push'")
        ->and(deployJob())->not->toHaveKey('needs');
})->group('structure');

it('names each deploy run after the commit it deploys or skips, with the same condition as the job', function (): void {
    expect(ciWorkflow('deploy.yml')['run-name'])
        ->toStartWith('${{ '.deployJob()['if'].' && ')
        ->toContain("format('Deploy: {0}', github.event.workflow_run.display_title)")
        ->toContain("format('Skipped, CI {0}: {1}', github.event.workflow_run.conclusion, github.event.workflow_run.display_title)");
})->group('structure');

it('never lets a newer deploy cancel one that has started', function (): void {
    expect(deployJob()['concurrency'])->toBe(['group' => 'deploy-prod', 'cancel-in-progress' => false])
        ->and(deployJob()['runs-on'])->toBe(['self-hosted', 'homelab']);
})->group('structure');

it('deploys the commit CI tested, never the default branch head', function (): void {
    $text = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
    $checkout = deployJob()['steps'][0];

    expect($text)->not->toContain('github.sha')
        ->and($checkout['uses'])->toStartWith('actions/checkout@')
        ->and($checkout['with']['ref'])->toBe('${{ '.HEAD_SHA.' }}')
        ->and(deployJobStep('Pull the image built on the hosted runner')['run'])->toContain('$APP_IMAGE:${{ '.HEAD_SHA.' }}')
        ->and(ciWorkflow('deploy.yml')['jobs']['notify']['with']['message'])->toContain(HEAD_SHA);
})->group('structure');

it('skips a commit main has moved past before touching prod', function (): void {
    $tip = deployJob()['steps'][1];

    expect($tip['id'])->toBe('tip')
        ->and($tip['run'])->toContain('git ls-remote origin refs/heads/main')
        ->toContain('if [ "$tip" = "${{ '.HEAD_SHA.' }}" ]; then');
})->group('structure');

it('rolls back a failed deploy that got past the newest-commit check', function (): void {
    expect(deployJobStep('Roll back on failure')['if'])->toBe("failure() && steps.tip.outputs.current == 'true'");
})->group('structure');
