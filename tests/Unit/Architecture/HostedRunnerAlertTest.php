<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

function workflowDoc(string $file): array
{
    return Yaml::parseFile(base_path(".github/workflows/{$file}"));
}

function expectHostedAlertJob(array $job, string $needs, bool $alertOnCancel = false): void
{
    $condition = $alertOnCancel
        ? "always() && (needs.{$needs}.result == 'failure' || needs.{$needs}.result == 'cancelled')"
        : "always() && needs.{$needs}.result == 'failure'";

    expect($job['needs'])->toBe([$needs])
        ->and($job['if'])->toBe($condition)
        ->and($job['uses'])->toBe('./.github/workflows/maintainer-alert.yml')
        ->and(array_keys($job['secrets']))->toBe(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_MAINTAINER_CHAT_ID'])
        ->and($job['with']['message'])->not->toBeEmpty();
}

it('pushes the maintainer alert from a hosted runner with the nightly-audit secrets', function (): void {
    $workflow = workflowDoc('maintainer-alert.yml');
    $job = $workflow['jobs']['telegram'];

    expect($workflow['on'])->toHaveKey('workflow_call')
        ->and(array_keys($workflow['on']['workflow_call']['secrets']))->toBe(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_MAINTAINER_CHAT_ID'])
        ->and($job['runs-on'])->toBe('ubuntu-26.04-arm')
        ->and($job['timeout-minutes'])->toBeInt();
})->group('structure');

it('alerts from a hosted runner when the deploy job fails or is cancelled', function (): void {
    $notify = workflowDoc('deploy.yml')['jobs']['notify'];

    expectHostedAlertJob($notify, 'deploy', alertOnCancel: true);
    expect($notify['with']['message'])->toContain('github.event.workflow_run.head_sha');
})->group('structure');

it('alerts from a hosted runner when the nightly backup job fails', function (): void {
    expectHostedAlertJob(workflowDoc('nightly-backup.yml')['jobs']['notify'], 'nightly-backup');
})->group('structure');

it('watches the newest successful nightly backup daily with a lowerable threshold', function (): void {
    $workflow = workflowDoc('backup-watchdog.yml');
    $check = $workflow['jobs']['check'];
    $measure = collect($check['steps'])->firstWhere('id', 'age');

    expect($workflow['on']['schedule'][0]['cron'])->toBe('37 1 * * *')
        ->and($workflow['on']['workflow_dispatch']['inputs']['max_age_hours']['default'])->toBe(26)
        ->and($workflow['permissions'])->toBe(['actions' => 'read'])
        ->and($check['runs-on'])->toBe('ubuntu-26.04-arm')
        ->and($check['timeout-minutes'])->toBeInt()
        ->and($measure['env']['MAX_AGE_HOURS'])->toBe('${{ inputs.max_age_hours || 26 }}')
        ->and($measure['run'])->toContain("gh run list --workflow 'Nightly backup' --status success --limit 1")
        ->and($measure['run'])->toContain('no successful Nightly backup run exists');

    expect($workflow['jobs']['notify']['needs'])->toBe(['check'])
        ->and($workflow['jobs']['notify']['if'])->toBe("always() && needs.check.result == 'failure'")
        ->and($workflow['jobs']['notify']['uses'])->toBe('./.github/workflows/maintainer-alert.yml');
})->group('structure');
