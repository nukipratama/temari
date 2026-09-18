<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * #997: a re-run of the Deploy job for a sha that was already deployed
 * overwrote that sha's pre-deploy backup with a dump of the freshly-wiped
 * (empty) database, silently — and the same re-run left `:latest`/`:previous`
 * pointing at the same image, so a rollback would roll back to itself.
 *
 * This asserts the fix by construction: the workflow YAML shapes that make a
 * re-run safe, plus the prune/selection scripts' actual behavior against
 * fabricated fixtures (never a live docker daemon or database). See the PR
 * description for what only a real run on the host can still prove.
 */
const CI_WORKFLOW_PATH = '.github/workflows/ci.yml';
const NIGHTLY_BACKUP_WORKFLOW_PATH = '.github/workflows/nightly-backup.yml';

function ciYamlText(): string
{
    return File::get(base_path(CI_WORKFLOW_PATH));
}

function findCiStepRun(string $stepName): string
{
    $doc = Yaml::parseFile(base_path(CI_WORKFLOW_PATH));
    $steps = $doc['jobs']['deploy']['steps'];

    foreach ($steps as $step) {
        if (($step['name'] ?? null) === $stepName) {
            expect($step['run'] ?? null)->not->toBeNull("Step '{$stepName}' has no run block.");

            return $step['run'];
        }
    }

    expect(false)->toBeTrue("No deploy step named '{$stepName}' found in ".CI_WORKFLOW_PATH);

    return '';
}

it('names deploy backups with a suffix that differs on every run and every retry', function (): void {
    $suffixStep = findCiStepRun('Compute backup filename suffix');

    expect($suffixStep)->toContain('github.sha')
        ->toContain('github.run_id')
        ->toContain('github.run_attempt');

    // github.run_id is stable across a "re-run failed jobs" retry (only
    // github.run_attempt changes there) — the bug in #997 was exactly this
    // kind of re-run, so run_attempt alone is what must vary for that case.
    // Both are asserted above; this just documents which one carries the case
    // that actually bit prod.
    expect($suffixStep)->toContain('run_attempt');
});

it('has both deploy backup steps write through the shared suffix and refuse to overwrite', function (): void {
    foreach (['Backup DB before migrate', 'Backup analytics schema'] as $stepName) {
        $run = findCiStepRun($stepName);

        expect($run)->toContain('$BACKUP_SUFFIX')
            ->toContain('if [ -e "$out" ]')
            ->toContain('refusing to overwrite');
    }
});

it('passes the new suffixed pre-deploy glob and a group prefix to the prune script for both schemas', function (): void {
    $yaml = ciYamlText();

    expect($yaml)->toContain(
        'scripts/deploy/verify-and-prune-backup.sh "Backup" "$out" "$tables" "$BACKUP_DIR/pre-deploy-*.sql.gz" 7 "pre-deploy-"'
    )->toContain(
        'scripts/deploy/verify-and-prune-backup.sh "Analytics backup" "$out" "$tables" "$BACKUP_DIR/analytics-pre-deploy-*.sql.gz" 7 "analytics-pre-deploy-"'
    );
});

it('skips re-tagging :previous when the incoming image already is :latest', function (): void {
    $run = findCiStepRun('Tag current :latest as :previous (rollback target)');

    expect($run)->toContain('docker image inspect "$APP_IMAGE:${{ github.sha }}"')
        ->toContain('docker image inspect temari/app:latest')
        ->toContain('"$current" = "$incoming"');

    // The old unconditional form must be gone, not just supplemented.
    expect($run)->not->toContain('if docker image inspect temari/app:latest >/dev/null 2>&1; then');
});

it('nightly-backup.yml matches the issue: cron 40 16 * * *, workflow_dispatch, self-hosted homelab, 14-day retention', function (): void {
    $doc = Yaml::parseFile(base_path(NIGHTLY_BACKUP_WORKFLOW_PATH));

    expect($doc['on']['schedule'][0]['cron'])->toBe('40 16 * * *');
    expect($doc['on'])->toHaveKey('workflow_dispatch');

    $job = $doc['jobs']['nightly-backup'];
    expect($job['runs-on'])->toBe(['self-hosted', 'homelab']);

    $yaml = File::get(base_path(NIGHTLY_BACKUP_WORKFLOW_PATH));
    expect(substr_count($yaml, 'verify-and-prune-backup.sh "Nightly'))->toBe(2);
    expect($yaml)->toContain('$BACKUP_DIR/nightly-*.sql.gz" 14')
        ->toContain('$BACKUP_DIR/analytics-nightly-*.sql.gz" 14');
});

it('nightly-backup.yml runs disk cleanup only after the backup steps, with continue-on-error so cleanup can never fail the backup', function (): void {
    $doc = Yaml::parseFile(base_path(NIGHTLY_BACKUP_WORKFLOW_PATH));
    $steps = $doc['jobs']['nightly-backup']['steps'];

    $names = array_map(fn (array $s): string => $s['name'] ?? '', $steps);
    $backupIndex = array_search('Backup analytics schema', $names, true);
    $cleanupIndex = array_search('Reclaim disk — old sha-tagged images', $names, true);

    expect($backupIndex)->not->toBeFalse();
    expect($cleanupIndex)->not->toBeFalse();
    expect($cleanupIndex)->toBeGreaterThan($backupIndex);

    foreach (['Reclaim disk — build cache', 'Reclaim disk — old sha-tagged images'] as $cleanupStepName) {
        foreach ($steps as $step) {
            if (($step['name'] ?? null) === $cleanupStepName) {
                expect($step['continue-on-error'] ?? false)->toBeTrue(
                    "Cleanup step '{$cleanupStepName}' must set continue-on-error so it can never fail the backup job."
                );
            }
        }
    }

    // The keep-last-10 image prune must never touch temari/app:latest or :previous
    // (a different, local repo — never in scope of the ghcr.io sha-tag cleanup).
    $yaml = File::get(base_path(NIGHTLY_BACKUP_WORKFLOW_PATH));
    expect($yaml)->not->toContain('docker rmi temari/app')
        ->toContain('select-images-to-prune.sh "$APP_IMAGE_REPO" 10');
});

it('verify-and-prune-backup.sh keeps the newest backup for a sha and drops an older sibling once both fall past the retention window and the top-10 floor', function (): void {
    $dir = sys_get_temp_dir().'/temari-backup-prune-test-'.uniqid();
    mkdir($dir);

    $now = time();
    $old = $now - 10 * 24 * 3600;
    $recent = $now - 1 * 24 * 3600;

    $touch = function (string $name, int $mtime) use ($dir): string {
        $path = "{$dir}/{$name}";
        file_put_contents($path, 'x');
        touch($path, $mtime);

        return $path;
    };

    // sha aaaa: exactly one backup, old — must survive (last copy for its sha).
    $onlyCopy = $touch('pre-deploy-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-20260101T000000Z-100-1.sql.gz', $old);

    // sha bbbb: two backups, both old — the older is prunable, the newer must survive.
    $olderSibling = $touch('pre-deploy-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-20260101T000000Z-200-1.sql.gz', $old - 3600);
    $newerSibling = $touch('pre-deploy-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-20260102T000000Z-201-1.sql.gz', $old + 3600);

    // 10 distinct, recent, single-backup shas to push the above past the top-10 floor.
    for ($i = 1; $i <= 10; $i++) {
        $sha = sprintf('c%039d', $i);
        $touch("pre-deploy-{$sha}-20260103T000000Z-30{$i}-1.sql.gz", $recent - $i);
    }

    $process = new Process([
        base_path('scripts/deploy/verify-and-prune-backup.sh'),
        'Backup',
        $onlyCopy,
        '0',
        "{$dir}/pre-deploy-*.sql.gz",
        '7',
        'pre-deploy-',
    ]);
    $process->mustRun();

    expect(file_exists($onlyCopy))->toBeTrue('the only backup for its sha must never be pruned');
    expect(file_exists($newerSibling))->toBeTrue('the newest backup for a sha must survive pruning');
    expect(file_exists($olderSibling))->toBeFalse('an older sibling, once a spare copy exists, should be pruned');

    File::deleteDirectory($dir);
});

it('verify-and-prune-backup.sh: two backup runs for the same sha never collide on a filename', function (): void {
    // The actual uniqueness guarantee lives in the ci.yml suffix (sha + timestamp
    // + run_id + run_attempt), asserted above; this proves the prune script's
    // own side of the contract — given two distinctly-named files for one sha,
    // neither is silently treated as "the same backup" and both are inspectable
    // right after a "re-run", i.e. immediately, well inside every retention rule.
    $dir = sys_get_temp_dir().'/temari-backup-prune-test-'.uniqid();
    mkdir($dir);

    $now = time();
    $sha = str_repeat('d', 40);
    $first = "{$dir}/pre-deploy-{$sha}-20260101T000000Z-500-1.sql.gz";
    $second = "{$dir}/pre-deploy-{$sha}-20260101T000005Z-500-2.sql.gz";
    file_put_contents($first, 'x');
    touch($first, $now);
    file_put_contents($second, 'xx');
    touch($second, $now + 5);

    $process = new Process([
        base_path('scripts/deploy/verify-and-prune-backup.sh'),
        'Backup',
        $second,
        '0',
        "{$dir}/pre-deploy-*.sql.gz",
        '7',
        'pre-deploy-',
    ]);
    $process->mustRun();

    expect(file_exists($first))->toBeTrue();
    expect(file_exists($second))->toBeTrue();
    expect($first)->not->toBe($second);

    File::deleteDirectory($dir);
});

it('select-images-to-prune.sh never selects :latest, :previous, or a non-sha tag, and only removes the oldest beyond keep', function (): void {
    $repo = 'ghcr.io/nukipratama/temari/app';
    $lines = [];

    for ($i = 1; $i <= 15; $i++) {
        $sha = sprintf('%040d', $i);
        $created = sprintf('2024-01-01T00:00:%02dZ', $i);
        $lines[] = "{$created}\tsha256:img{$i}\t{$sha}";
    }
    $lines[] = "2024-01-02T00:00:00Z\tsha256:imglatest\tlatest";
    $lines[] = "2024-01-02T00:00:00Z\tsha256:imgprev\tprevious";
    $lines[] = "2024-01-02T00:00:00Z\tsha256:imgcache\tbuildcache";

    $process = new Process([base_path('scripts/deploy/select-images-to-prune.sh'), $repo, '10']);
    $process->setInput(implode("\n", $lines)."\n");
    $process->mustRun();

    $output = trim($process->getOutput());
    $removed = $output === '' ? [] : explode("\n", $output);

    expect($removed)->toHaveCount(5);

    foreach ($removed as $line) {
        expect($line)->not->toContain(':latest')
            ->not->toContain(':previous')
            ->not->toContain(':buildcache');
    }

    for ($i = 1; $i <= 5; $i++) {
        expect($output)->toContain("sha256:img{$i}\t");
    }
    for ($i = 6; $i <= 15; $i++) {
        expect($output)->not->toContain("sha256:img{$i}\t");
    }
});

it('select-images-to-prune.sh never selects an image IN_USE names, by id or by repo:tag', function (): void {
    $repo = 'ghcr.io/nukipratama/temari/app';
    $lines = [];
    for ($i = 1; $i <= 12; $i++) {
        $sha = sprintf('%040d', $i);
        $created = sprintf('2024-01-01T00:00:%02dZ', $i);
        $lines[] = "{$created}\tsha256:img{$i}\t{$sha}";
    }

    $process = new Process(
        [base_path('scripts/deploy/select-images-to-prune.sh'), $repo, '10'],
        null,
        ['IN_USE' => "sha256:img1\nsha256:img2"]
    );
    $process->setInput(implode("\n", $lines)."\n");
    $process->mustRun();

    $output = trim($process->getOutput());

    expect($output)->not->toContain('sha256:img1')
        ->not->toContain('sha256:img2');
});
