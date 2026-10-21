<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** @param  array<string, string>  $env */
function recoveryCommands(array $env): string
{
    $process = new Process([base_path('scripts/deploy/print-recovery-commands.sh')], null, $env + ['GITHUB_REPOSITORY' => 'owner/repo']);
    $process->mustRun();

    return $process->getOutput();
}

it('prints the restore, rollback and maintenance commands for the dumps this deploy wrote', function (): void {
    $dir = sys_get_temp_dir().'/recovery-'.uniqid();
    File::ensureDirectoryExists($dir);
    touch("{$dir}/pre-deploy-abc-1.sql.gz");
    touch("{$dir}/analytics-pre-deploy-abc-1.sql.gz");
    $sha = str_repeat('a', 40);

    $output = recoveryCommands([
        'BACKUP_DIR' => $dir,
        'BACKUP_SUFFIX' => 'abc-1',
        'PREVIOUS_SHA' => $sha,
        'DEPLOY_ENABLED_MAINTENANCE' => 'true',
    ]);

    expect($output)->toContain('sudo docker compose -f compose.prod.yaml stop scheduler horizon')
        ->toContain("sudo ./scripts/restore-db.sh {$dir}/pre-deploy-abc-1.sql.gz")
        ->toContain("sudo ./scripts/restore-db.sh {$dir}/analytics-pre-deploy-abc-1.sql.gz")
        ->toContain("gh workflow run release-rollback.yml --repo owner/repo -f sha={$sha}")
        ->toContain('sudo docker compose -f compose.prod.yaml exec -T app php artisan up');

    File::deleteDirectory($dir);
});

it('falls back to :previous and leaves owner maintenance alone when the deploy failed before its backup', function (): void {
    $output = recoveryCommands(['BACKUP_DIR' => sys_get_temp_dir().'/recovery-missing']);

    expect($output)->toContain('This run wrote no pre-deploy dump')
        ->toContain("gh workflow run release-rollback.yml --repo owner/repo\n")
        ->and($output)->not->toContain('-f sha=')
        ->and($output)->not->toContain('php artisan up');
});

it('prints the recovery commands from the deploy rollback step on the migration path', function (): void {
    $steps = collect(arrayOf(data_get(Yaml::parseFile(base_path('.github/workflows/release-deploy.yml')), 'jobs.deploy.steps')));

    expect(data_get($steps->firstWhere('name', 'Roll back on failure'), 'run'))
        ->toContain('scripts/deploy/print-recovery-commands.sh | tee -a "$GITHUB_STEP_SUMMARY"')
        ->and(data_get($steps->firstWhere('name', 'Tag current :latest as :previous (rollback target)'), 'run'))
        ->toContain('PREVIOUS_SHA=');
});
