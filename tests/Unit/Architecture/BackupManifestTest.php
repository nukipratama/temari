<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function runCountRowsQuery(string $tables): Process
{
    $process = new Process([base_path('scripts/deploy/count-rows-query.sh')]);
    $process->setInput($tables);
    $process->run();

    return $process;
}

function runManifestCheck(string $manifest, string $restored): Process
{
    $manifestFile = tempnam(sys_get_temp_dir(), 'backup-manifest-');
    $restoredFile = tempnam(sys_get_temp_dir(), 'restored-counts-');
    if ($manifestFile === false || $restoredFile === false) {
        throw new RuntimeException('Could not create manifest fixtures.');
    }
    file_put_contents($manifestFile, $manifest);
    file_put_contents($restoredFile, $restored);

    $process = new Process([base_path('scripts/deploy/check-restore-manifest.sh'), 'default', $manifestFile, $restoredFile]);
    $process->run();

    unlink($manifestFile);
    unlink($restoredFile);

    return $process;
}

it('builds one exact-count query over every listed table', function (): void {
    $process = runCountRowsQuery("users\nactivities\n\n");

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))
        ->toBe("SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'activities', COUNT(*) FROM activities");
})->group('structure');

it('refuses a table name that is not a plain identifier', function (string $name): void {
    $process = runCountRowsQuery("users\n{$name}\n");

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('');
})->with(['`rm -rf`', 'a b', "x'y", 'a;b'])->group('structure');

it('refuses an empty table list', function (): void {
    expect(runCountRowsQuery("\n")->getExitCode())->toBe(1);
})->group('structure');

it('passes a restore holding every manifest table with at least its dump-time rows', function (): void {
    $process = runManifestCheck("users\t4\nactivities\t564\n", "activities\t566\nusers\t4\nextra\t1\n");

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('activities')->toContain('OK');
})->group('structure');

it('fails a restore that lost a table or rows the manifest recorded', function (string $restored, string $reason): void {
    $process = runManifestCheck("users\t4\nactivities\t564\n", $restored);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain($reason);
})->with([
    'missing table' => ["users\t4\n", 'activities'],
    'fewer rows' => ["users\t4\nactivities\t500\n", 'FAIL'],
])->group('structure');

it('fails on an empty manifest instead of passing nothing', function (): void {
    expect(runManifestCheck('', "users\t4\n")->getExitCode())->toBe(1);
})->group('structure');

it('writes each nightly manifest before its dump and never lets a manifest failure stop the dump', function (): void {
    $steps = collect(Yaml::parseFile(base_path('.github/workflows/nightly-backup.yml'))['jobs']['nightly-backup']['steps']);

    foreach (['Backup DB' => 'nightly-', 'Backup analytics schema' => 'analytics-nightly-'] as $name => $prefix) {
        $run = $steps->firstWhere('name', $name)['run'];

        expect($run)->toContain("{$prefix}\$NIGHTLY_TIMESTAMP.manifest")
            ->toContain('scripts/deploy/count-rows-query.sh')
            ->toContain('::warning::')
            ->and(strpos((string) $run, '.manifest'))->toBeLessThan(strpos((string) $run, 'mysqldump'));
    }
})->group('structure');

it('verifies a nightly restore against its manifest, not live', function (): void {
    $verify = collect(Yaml::parseFile(base_path('.github/workflows/restore-dry-run.yml'))['jobs']['restore-dry-run']['steps'])
        ->firstWhere('id', 'verify');

    expect($verify['env'])->toBe([
        'KIND' => "\${{ inputs.kind || 'nightly' }}",
        'APP_MANIFEST' => '${{ steps.pick.outputs.app_manifest }}',
        'ANALYTICS_MANIFEST' => '${{ steps.pick.outputs.analytics_manifest }}',
    ])
        ->and($verify['run'])->toContain('scripts/deploy/check-restore-manifest.sh "$schema" "$manifest" "$restored"')
        ->toContain('check_manifest sql "default" "$APP_MANIFEST"')
        ->toContain('check_manifest sql_analytics "analytics" "$ANALYTICS_MANIFEST"');
})->group('structure');

it('dumps as the read-only backup user with its GTID position recorded as a comment and no masking-policy statements', function (string $workflow): void {
    preg_match_all('/mysqldump -[^\'\n]*/', (string) file_get_contents(base_path($workflow)), $dumps);

    expect($dumps[0])->not->toBeEmpty();

    foreach ($dumps[0] as $dump) {
        expect($dump)->toContain('--set-gtid-purged=COMMENTED')
            ->toContain('--loose-skip-masking-policies')
            ->toContain('-utemari_backup');
    }
})->with([
    '.github/workflows/deploy.yml',
    '.github/workflows/nightly-backup.yml',
    '.github/workflows/nightly-audit.yml',
])->group('structure');
