<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function runRestoreDryRunCountCheck(
    string $liveStatus,
    string $liveOutput,
    string $throwawayStatus,
    string $throwawayOutput,
): Process {
    $process = new Process([
        base_path('scripts/deploy/check-restore-counts.sh'),
        'default',
        'activities',
        $liveStatus,
        $liveOutput,
        $throwawayStatus,
        $throwawayOutput,
    ]);
    $process->run();

    return $process;
}

it('restore-dry-run.yml delegates count validation to the tested helper', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/restore-dry-run.yml'));
    $steps = $workflow['jobs']['restore-dry-run']['steps'];
    $verifyStep = collect($steps)->firstWhere('name', 'Verify the restore against live (read-only)');

    expect($verifyStep['run'] ?? '')->toContain('scripts/deploy/check-restore-counts.sh');
});

it('fails when the live count query fails', function (): void {
    $process = runRestoreDryRunCountCheck('1', '', '0', '100');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('live count query failed');
});

it('fails when the throwaway count query fails', function (): void {
    $process = runRestoreDryRunCountCheck('0', '100', '1', '');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('throwaway count query failed');
});

it('fails when both count queries fail', function (): void {
    $process = runRestoreDryRunCountCheck('1', '', '1', '');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('live count query failed');
});

it('fails when a count query returns empty output', function (): void {
    $process = runRestoreDryRunCountCheck('0', '', '0', '100');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('invalid output');
});

it('fails when a count query returns non-numeric output', function (): void {
    $process = runRestoreDryRunCountCheck('0', '100 rows', '0', '100');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('invalid output');
});

it('accepts zero rows in both the live and throwaway databases', function (): void {
    $process = runRestoreDryRunCountCheck('0', '0', '0', '0');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('live=0')
        ->toContain('throwaway=0')
        ->toContain('OK');
});

it('allows a historical restore count below the live count', function (): void {
    $process = runRestoreDryRunCountCheck('0', '100', '0', '94');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('live=100')
        ->toContain('throwaway=94')
        ->toContain('OK');
});
