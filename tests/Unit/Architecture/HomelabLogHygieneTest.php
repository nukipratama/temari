<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/** @return list<string> */
function homelabStepRuns(string $workflow): array
{
    $runs = [];
    foreach (arrayOf(data_get(Yaml::parseFile(base_path(".github/workflows/{$workflow}")), 'jobs')) as $job) {
        foreach (arrayOf(data_get($job, 'steps', [])) as $step) {
            $run = data_get($step, 'run');
            if (is_string($run)) {
                $runs[] = $run;
            }
        }
    }

    return $runs;
}

it('masks the backup password in every step that reads it from the host env file', function (string $workflow): void {
    $reads = array_values(array_filter(
        homelabStepRuns($workflow),
        fn (string $run): bool => str_contains($run, 'DB_BACKUP_PASSWORD=$(scripts/deploy/backup-password.sh)'),
    ));

    expect($reads)->not->toBeEmpty();
    foreach ($reads as $run) {
        expect($run)->toContain("DB_BACKUP_PASSWORD=\$(scripts/deploy/backup-password.sh)\necho \"::add-mask::\$DB_BACKUP_PASSWORD\"");
    }
})->with(['release-deploy.yml', 'data-backup.yml'])->group('structure');

it('masks the throwaway database password before the rehearsal env file is written', function (): void {
    $run = collect(homelabStepRuns('data-restore-dry-run.yml'))
        ->first(fn (string $run): bool => str_contains($run, 'db_password=$(restore_env DB_PASSWORD)'));

    expect($run)->toBeString();
    assert(is_string($run));
    $mask = strpos($run, 'echo "::add-mask::$db_password"');
    $write = strpos($run, '> "$REHEARSAL_ENV_FILE"');

    expect($mask)->toBeInt()
        ->and($write)->toBeInt();
    assert(is_int($mask) && is_int($write));
    expect($mask)->toBeLessThan($write);
})->group('structure');

it('keeps disk sizes out of the disk hygiene log and summary unless the headroom check fails', function (): void {
    $run = collect(homelabStepRuns('ops-disk.yml'))
        ->first(fn (string $run): bool => str_contains($run, 'binlog_bytes='));

    expect($run)->toBeString();
    assert(is_string($run));
    expect($run)->not->toContain('echo "$line"')
        ->and($run)->not->toContain('"$line" >> "$GITHUB_STEP_SUMMARY"')
        ->and($run)->toContain('echo "::error::Another 7 days of binlogs plus a nightly dump no longer fit the free disk: $line"')
        ->toContain('echo "## Binlog disk headroom"$\'\n\'"OK" >> "$GITHUB_STEP_SUMMARY"');
})->group('structure');
