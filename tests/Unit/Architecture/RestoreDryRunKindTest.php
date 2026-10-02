<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function restoreDryRunWorkflow(): array
{
    return Yaml::parseFile(base_path('.github/workflows/restore-dry-run.yml'));
}

/**
 * @param  array<string, string>  $env
 * @return array{0: Process, 1: string}
 */
function runRestorePick(string $backupDir, array $env): array
{
    $pick = collect(restoreDryRunWorkflow()['jobs']['restore-dry-run']['steps'])->firstWhere('id', 'pick');
    $output = tempnam(sys_get_temp_dir(), 'restore-pick-output-');
    if ($output === false) {
        throw new RuntimeException('Could not create the GITHUB_OUTPUT fixture.');
    }

    $process = new Process(['sh', '-c', $pick['run']], null, [
        'BACKUP_DIR' => $backupDir,
        'GITHUB_OUTPUT' => $output,
        'KIND' => 'nightly',
        'BACKUP_SHA' => '',
        ...$env,
    ]);
    $process->run();

    $written = (string) file_get_contents($output);
    unlink($output);

    return [$process, $written];
}

function restoreBackupFixture(): string
{
    $dir = sys_get_temp_dir().'/temari-restore-pick-'.uniqid();
    mkdir($dir);

    $files = [
        'nightly-20260920T164000Z.sql.gz' => 100,
        'analytics-nightly-20260920T164000Z.sql.gz' => 100,
        'nightly-20260927T164000Z.sql.gz' => 200,
        'analytics-nightly-20260927T164000Z.sql.gz' => 200,
        'pre-deploy-abc123-20260925T010000Z-42-1.sql.gz' => 150,
        'analytics-pre-deploy-abc123-20260925T010000Z-42-1.sql.gz' => 150,
    ];
    foreach ($files as $name => $offset) {
        touch("{$dir}/{$name}", 1_790_000_000 + $offset);
    }

    return $dir;
}

it('runs weekly on the homelab runner inside the deploy-prod group, defaulting to the nightly kind', function (): void {
    $workflow = restoreDryRunWorkflow();
    $pick = collect($workflow['jobs']['restore-dry-run']['steps'])->firstWhere('id', 'pick');
    $kind = $workflow['on']['workflow_dispatch']['inputs']['kind'];

    expect($workflow['on']['schedule'][0]['cron'])->toBe('7 21 * * 6')
        ->and($kind['options'])->toBe(['nightly', 'pre-deploy'])
        ->and($kind['default'])->toBe('nightly')
        ->and($pick['env']['KIND'])->toBe("\${{ inputs.kind || 'nightly' }}")
        ->and($workflow['concurrency']['group'])->toBe('deploy-prod')
        ->and($workflow['jobs']['restore-dry-run']['runs-on'])->toBe(['self-hosted', 'homelab']);
})->group('structure');

it('alerts through the hosted maintainer-alert workflow when the dry-run fails', function (): void {
    $notify = restoreDryRunWorkflow()['jobs']['notify'];

    expect($notify['needs'])->toBe(['restore-dry-run'])
        ->and($notify['if'])->toBe("always() && needs.restore-dry-run.result == 'failure'")
        ->and($notify['uses'])->toBe('./.github/workflows/maintainer-alert.yml')
        ->and(array_keys($notify['secrets']))->toBe(['TELEGRAM_BOT_TOKEN', 'TELEGRAM_MAINTAINER_CHAT_ID']);
})->group('structure');

it('picks the newest nightly pair for kind=nightly', function (): void {
    $dir = restoreBackupFixture();
    [$process, $output] = runRestorePick($dir, []);
    File::deleteDirectory($dir);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($output)->toContain("app={$dir}/nightly-20260927T164000Z.sql.gz")
        ->toContain("analytics={$dir}/analytics-nightly-20260927T164000Z.sql.gz");
})->group('structure');

it('picks the newest pre-deploy pair for kind=pre-deploy, and by sha', function (): void {
    $dir = restoreBackupFixture();
    [$newest, $newestOutput] = runRestorePick($dir, ['KIND' => 'pre-deploy']);
    [$bySha, $byShaOutput] = runRestorePick($dir, ['KIND' => 'pre-deploy', 'BACKUP_SHA' => 'abc123']);
    File::deleteDirectory($dir);

    foreach ([[$newest, $newestOutput], [$bySha, $byShaOutput]] as [$process, $output]) {
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($output)->toContain("app={$dir}/pre-deploy-abc123-20260925T010000Z-42-1.sql.gz")
            ->toContain("analytics={$dir}/analytics-pre-deploy-abc123-20260925T010000Z-42-1.sql.gz");
    }
})->group('structure');

it('fails the pick when the analytics sibling is missing, a sha is given for nightly, or no dump exists', function (): void {
    $dir = restoreBackupFixture();
    unlink("{$dir}/analytics-nightly-20260927T164000Z.sql.gz");
    [$missingPair] = runRestorePick($dir, []);
    [$shaOnNightly] = runRestorePick($dir, ['BACKUP_SHA' => 'abc123']);
    File::deleteDirectory($dir);

    $empty = sys_get_temp_dir().'/temari-restore-pick-empty-'.uniqid();
    mkdir($empty);
    [$noDump] = runRestorePick($empty, []);
    rmdir($empty);

    expect($missingPair->getExitCode())->toBe(1)
        ->and($missingPair->getOutput())->toContain('no matching analytics backup')
        ->and($shaOnNightly->getExitCode())->toBe(1)
        ->and($shaOnNightly->getOutput())->toContain('backup_sha only applies to kind=pre-deploy')
        ->and($noDump->getExitCode())->toBe(1)
        ->and($noDump->getOutput())->toContain('no nightly-*.sql.gz found');
})->group('structure');
