<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function runRestoreDryRunCountCheck(
    string $liveStatus,
    string $liveOutput,
    string $throwawayStatus,
    string $throwawayOutput,
    string $liveError = '',
    string $throwawayError = '',
): Process {
    $liveErrorFile = tempnam(sys_get_temp_dir(), 'restore-counts-live-');
    $throwawayErrorFile = tempnam(sys_get_temp_dir(), 'restore-counts-throwaway-');
    if ($liveErrorFile === false || $throwawayErrorFile === false) {
        throw new RuntimeException('Could not create restore count error fixtures.');
    }
    file_put_contents($liveErrorFile, $liveError);
    file_put_contents($throwawayErrorFile, $throwawayError);

    $process = new Process([
        base_path('scripts/deploy/check-restore-counts.sh'),
        'default',
        'activities',
        $liveStatus,
        $liveOutput,
        $throwawayStatus,
        $throwawayOutput,
        $liveErrorFile,
        $throwawayErrorFile,
    ]);
    $process->run();

    unlink($liveErrorFile);
    unlink($throwawayErrorFile);

    return $process;
}

it('restore-dry-run.yml delegates count validation to the tested helper', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/restore-dry-run.yml'));
    $steps = $workflow['jobs']['restore-dry-run']['steps'];
    $verifyStep = collect($steps)->firstWhere('name', 'Verify the restore against live (read-only)');

    expect($verifyStep['run'] ?? '')
        ->toContain('scripts/deploy/check-restore-counts.sh')
        ->toContain('live_err="$(mktemp)"')
        ->toContain('2>"$live_err"')
        ->toContain('"$live_err" "$throwaway_err"');
})->group('structure');

it('fails with the live query error when the live count query fails', function (): void {
    $process = runRestoreDryRunCountCheck('1', '', '0', '100', 'ERROR 2002: live connection refused');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('live count query failed')
        ->toContain('ERROR 2002: live connection refused');
})->group('structure');

it('fails with the throwaway query error when its count query fails', function (): void {
    $process = runRestoreDryRunCountCheck('0', '100', '1', '', '', 'ERROR 1146: throwaway table missing');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('throwaway count query failed')
        ->toContain('ERROR 1146: throwaway table missing');
})->group('structure');

it('prefers the live query error when both count queries fail', function (): void {
    $process = runRestoreDryRunCountCheck(
        '1',
        '',
        '1',
        '',
        'ERROR 2002: live connection refused',
        'ERROR 2002: throwaway connection refused',
    );
    $output = $process->getErrorOutput().$process->getOutput();

    expect($process->getExitCode())->toBe(1)
        ->and($output)->toContain('live count query failed')
        ->and($output)->toContain('ERROR 2002: live connection refused')
        ->and($output)->not->toContain('throwaway count query failed');
})->group('structure');

it('fails when a count query returns empty output', function (): void {
    $process = runRestoreDryRunCountCheck('0', '', '0', '100');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('invalid output');
})->group('structure');

it('fails when a count query returns non-numeric output', function (): void {
    $process = runRestoreDryRunCountCheck('0', '100 rows', '0', '100');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('invalid output');
})->group('structure');

it('accepts zero rows in both the live and throwaway databases', function (): void {
    $process = runRestoreDryRunCountCheck('0', '0', '0', '0');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('live=0')
        ->toContain('throwaway=0')
        ->toContain('OK');
})->group('structure');

it('allows a historical restore count below the live count', function (): void {
    $process = runRestoreDryRunCountCheck('0', '100', '0', '94');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('live=100')
        ->toContain('throwaway=94')
        ->toContain('OK');
})->group('structure');
