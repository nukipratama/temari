<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return array<mixed> */
function workflowDoc(string $file): array
{
    return arrayOf(Yaml::parseFile(base_path(".github/workflows/{$file}")));
}

function expectHostedAlertJob(mixed $job, string $needs, bool $alertOnCancel = false): void
{
    $condition = $alertOnCancel
        ? "always() && (needs.{$needs}.result == 'failure' || needs.{$needs}.result == 'cancelled')"
        : "always() && needs.{$needs}.result == 'failure'";

    expect(data_get($job, 'needs'))->toBe([$needs])
        ->and(data_get($job, 'if'))->toBe($condition)
        ->and(data_get($job, 'uses'))->toBe('./.github/workflows/_alert.yml')
        ->and(array_keys(arrayOf(data_get($job, 'secrets'))))->toBe(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_MAINTAINER_CHAT_ID'])
        ->and(data_get($job, 'with.message'))->not->toBeEmpty();
}

it('pushes the maintainer alert from a hosted runner with the nightly-audit secrets', function (): void {
    $workflow = workflowDoc('_alert.yml');
    $job = data_get($workflow, 'jobs.telegram');

    expect(data_get($workflow, 'on'))->toHaveKey('workflow_call')
        ->and(array_keys(arrayOf(data_get($workflow, 'on.workflow_call.secrets'))))->toBe(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_MAINTAINER_CHAT_ID'])
        ->and(data_get($job, 'runs-on'))->toBe('ubuntu-26.04-arm')
        ->and(data_get($job, 'timeout-minutes'))->toBeInt();
})->group('structure');

it('alerts from a hosted runner when the deploy job fails or is cancelled', function (): void {
    $notify = data_get(workflowDoc('release-deploy.yml'), 'jobs.notify');

    expectHostedAlertJob($notify, 'deploy', alertOnCancel: true);
    expect(data_get($notify, 'with.message'))->toContain('github.event.workflow_run.head_sha');
})->group('structure');

it('alerts from a hosted runner when the nightly backup job fails', function (): void {
    expectHostedAlertJob(data_get(workflowDoc('data-backup.yml'), 'jobs.notify'), 'nightly-backup');
})->group('structure');

it('watches the newest successful nightly backup daily with a lowerable threshold', function (): void {
    $workflow = workflowDoc('data-backup-watchdog.yml');
    $check = data_get($workflow, 'jobs.check');
    $measure = collect(arrayOf(data_get($check, 'steps')))->firstWhere('id', 'age');

    expect(data_get($workflow, 'on.schedule.0.cron'))->toBe('37 1 * * *')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.max_age_hours.default'))->toBe(26)
        ->and(data_get($workflow, 'permissions'))->toBe(['actions' => 'read'])
        ->and(data_get($check, 'runs-on'))->toBe('ubuntu-26.04-arm')
        ->and(data_get($check, 'timeout-minutes'))->toBeInt()
        ->and(data_get($measure, 'env.MAX_AGE_HOURS'))->toBe('${{ inputs.max_age_hours || 26 }}')
        ->and(data_get($measure, 'run'))->toContain("gh run list --workflow data-backup.yml --status success --limit 1")
        ->and(data_get($measure, 'run'))->toContain('no successful Nightly backup run exists');

    expect(data_get($workflow, 'jobs.notify.needs'))->toBe(['check'])
        ->and(data_get($workflow, 'jobs.notify.if'))->toBe("always() && needs.check.result == 'failure'")
        ->and(data_get($workflow, 'jobs.notify.uses'))->toBe('./.github/workflows/_alert.yml');
})->group('structure');
