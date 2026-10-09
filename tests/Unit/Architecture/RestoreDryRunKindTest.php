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
        ->and($workflow)->not->toHaveKey('concurrency')
        ->and($workflow['jobs']['restore-dry-run']['concurrency'])->toBe(['group' => 'deploy-prod', 'cancel-in-progress' => false])
        ->and($workflow['jobs']['restore-dry-run']['runs-on'])->toBe(['self-hosted', 'homelab']);
})->group('structure');

it('alerts through the hosted maintainer-alert workflow when the dry-run fails', function (): void {
    $notify = restoreDryRunWorkflow()['jobs']['notify'];

    expect($notify['needs'])->toBe(['build', 'restore-dry-run'])
        ->and($notify['if'])->toBe("always() && (needs.build.result == 'failure' || needs.restore-dry-run.result == 'failure')")
        ->and($notify['with']['message'])->toContain("format(', ref {0}', inputs.ref)")
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

/** @return array<string, mixed> */
function restoreDryRunStep(string $job, string $key, string $value): array
{
    return collect(restoreDryRunWorkflow()['jobs'][$job]['steps'])->firstWhere($key, $value) ?? [];
}

/**
 * @param  array<string, string|false>  $env
 * @return array{0: Process, 1: string}
 */
function runWithFakeDocker(string $script, string $fakeDocker, array $env): array
{
    $bin = sys_get_temp_dir().'/temari-fake-docker-'.uniqid();
    mkdir($bin);
    file_put_contents("{$bin}/docker", "#!/bin/sh\n{$fakeDocker}\n");
    chmod("{$bin}/docker", 0755);
    $output = "{$bin}/github-output";
    touch($output);

    $process = new Process(['sh', '-c', $script], null, [
        'PATH' => $bin.':'.getenv('PATH'),
        'GITHUB_OUTPUT' => $output,
        'DOCKER_LOG' => "{$bin}/docker-log",
        ...$env,
    ]);
    $process->run();

    return [$process, $bin];
}

it('builds the ref image on a hosted runner only when a ref is given, as ci.yml builds it but without a cache write', function (): void {
    $workflow = restoreDryRunWorkflow();
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));
    $build = $workflow['jobs']['build'];
    $push = restoreDryRunStep('build', 'name', 'Build and push the rehearsal image');
    $ciPush = collect($ci['jobs']['build']['steps'])->firstWhere('name', 'Build and push the runtime image');
    $uses = collect($build['steps'])->pluck('uses')->filter()->values()->all();
    $ciUses = collect($ci['jobs']['build']['steps'])->pluck('uses')->filter()->unique()->values()->all();

    expect($workflow['on']['workflow_dispatch']['inputs']['ref'])->toMatchArray(['required' => false, 'default' => ''])
        ->and($build['if'])->toBe("inputs.ref != ''")
        ->and($build['runs-on'])->toBe('ubuntu-26.04')
        ->and($build['permissions'])->toBe(['contents' => 'read', 'packages' => 'write'])
        ->and($build['env']['APP_IMAGE'])->toBe($ci['env']['APP_IMAGE'])
        ->and($build['steps'][0]['with']['ref'])->toBe('${{ inputs.ref }}')
        ->and(array_diff($uses, $ciUses))->toBe([])
        ->and($push['with'])->not->toHaveKey('cache-to')
        ->and($push['with']['push'])->toBeTrue()
        ->and(array_intersect_key($push['with'], array_flip(['context', 'file', 'platforms', 'labels', 'cache-from', 'provenance'])))
        ->toBe(array_intersect_key($ciPush['with'], array_flip(['context', 'file', 'platforms', 'labels', 'cache-from', 'provenance'])));
})->group('structure');

it('tags the ref image by its resolved sha, never the moving ref name', function (): void {
    $workflow = restoreDryRunWorkflow();
    $build = $workflow['jobs']['build'];
    $push = restoreDryRunStep('build', 'name', 'Build and push the rehearsal image');

    expect(restoreDryRunStep('build', 'id', 'resolve')['run'])->toBe('echo "sha=$(git rev-parse HEAD)" >> "$GITHUB_OUTPUT"')
        ->and($build['outputs'])->toBe([
            'sha' => '${{ steps.resolve.outputs.sha }}',
            'image' => '${{ env.APP_IMAGE }}:${{ steps.resolve.outputs.sha }}',
        ])
        ->and($push['with']['tags'])->toBe('${{ env.APP_IMAGE }}:${{ steps.resolve.outputs.sha }}')
        ->and($workflow['jobs']['restore-dry-run']['env']['REHEARSAL_IMAGE'])->toBe('${{ needs.build.outputs.image }}')
        ->and($workflow['jobs']['restore-dry-run']['env']['REHEARSAL_SHA'])->toBe('${{ needs.build.outputs.sha }}');
})->group('structure');

it('reuses an image tag that already exists instead of re-pushing a tag deploy.yml pulls', function (): void {
    $steps = collect(restoreDryRunWorkflow()['jobs']['build']['steps']);
    $check = restoreDryRunStep('build', 'id', 'existing');
    $fake = <<<'SH'
        case "$FAKE_INSPECT" in
          exists) exit 0 ;;
          missing) echo "ERROR: $4: not found" >&2; exit 1 ;;
          *) echo "ERROR: unexpected status from HEAD request to https://ghcr.io: 401 Unauthorized" >&2; exit 1 ;;
        esac
        SH;
    $env = ['APP_IMAGE' => 'ghcr.io/owner/repo/app', 'SHA' => str_repeat('a', 40)];

    $results = [];
    foreach (['exists', 'missing', 'denied'] as $case) {
        [$process, $bin] = runWithFakeDocker($check['run'], $fake, [...$env, 'FAKE_INSPECT' => $case]);
        $results[$case] = [$process, (string) file_get_contents("{$bin}/github-output")];
        File::deleteDirectory($bin);
    }

    expect($check['run'])->toContain('docker buildx imagetools inspect "$image"')
        ->and($steps->search(fn (array $step): bool => ($step['id'] ?? null) === 'existing'))
        ->toBeLessThan($steps->search(fn (array $step): bool => ($step['name'] ?? null) === 'Build and push the rehearsal image'))
        ->and(restoreDryRunStep('build', 'name', 'Build and push the rehearsal image')['if'])->toBe("steps.existing.outputs.exists == 'false'")
        ->and($results['exists'][0]->getExitCode())->toBe(0, $results['exists'][0]->getErrorOutput())
        ->and($results['exists'][1])->toBe("exists=true\n")
        ->and($results['missing'][0]->getExitCode())->toBe(0, $results['missing'][0]->getErrorOutput())
        ->and($results['missing'][1])->toBe("exists=false\n")
        ->and($results['denied'][0]->getExitCode())->toBe(1)
        ->and($results['denied'][0]->getOutput())->toContain('::error::could not inspect')
        ->and($results['denied'][1])->toBe('');
})->group('structure');

it('keeps every job but the build and notify on the homelab runner, under the deploy-prod lock', function (): void {
    $jobs = restoreDryRunWorkflow()['jobs'];
    $offHomelab = collect($jobs)->reject(fn (array $job): bool => ($job['runs-on'] ?? null) === ['self-hosted', 'homelab'])->keys()->all();
    $homelab = $jobs['restore-dry-run'];

    expect($offHomelab)->toBe(['build', 'notify'])
        ->and($homelab['needs'])->toBe(['build'])
        ->and($homelab['if'])->toBe("\${{ !cancelled() && (needs.build.result == 'success' || needs.build.result == 'skipped') }}")
        ->and($homelab['permissions'])->toBe(['contents' => 'read', 'packages' => 'read'])
        ->and($homelab['timeout-minutes'])->toBe(45)
        ->and($jobs['build'])->not->toHaveKey('concurrency');
})->group('structure');

it('runs the ref image as a hardened overlay service with no outside env file, ports or route out', function (): void {
    $base = Yaml::parseFile(base_path('deploy/restore-dry-run-compose.yml'));
    $overlay = Yaml::parseFile(base_path('deploy/restore-dry-run-rehearsal-compose.yml'));
    $app = $overlay['services']['app_rehearsal'];

    expect(array_keys($overlay['services']))->toBe(['app_rehearsal'])
        ->and($overlay)->not->toHaveKey('networks')
        ->and($base['services'])->not->toHaveKey('app_rehearsal')
        ->and($app['image'])->toBe('${REHEARSAL_IMAGE:?}')
        ->and($app['pull_policy'])->toBe('never')
        ->and($app['env_file'])->toBe(['${REHEARSAL_ENV_FILE:?}'])
        ->and(json_encode($app))->not->toContain('/opt/temari')
        ->and(array_intersect_key($app, array_flip(['ports', 'volumes', 'build', 'profiles'])))->toBe([])
        ->and($app['cap_drop'])->toBe(['ALL'])
        ->and($app['security_opt'])->toBe(['no-new-privileges:true'])
        ->and($app['networks'])->toBe(['restore'])
        ->and($base['services']['mysql_restore']['networks'])->toBe(['restore'])
        ->and($base['networks'])->toBe(['restore' => ['internal' => true]]);
})->group('structure');

it('loads the overlay only through the ref-gated rehearsal compose command', function (): void {
    $workflow = restoreDryRunWorkflow();
    $text = (string) File::get(base_path('.github/workflows/restore-dry-run.yml'));
    $steps = $workflow['jobs']['restore-dry-run']['steps'];

    expect(substr_count($text, 'restore-dry-run-rehearsal-compose.yml'))->toBe(1)
        ->and($workflow['jobs']['restore-dry-run']['env']['REHEARSAL_COMPOSE'])
        ->toBe('docker compose -p temari-restore -f deploy/restore-dry-run-compose.yml -f deploy/restore-dry-run-rehearsal-compose.yml');

    foreach ($steps as $step) {
        if (! str_contains($step['run'] ?? '', '$REHEARSAL_COMPOSE')) {
            continue;
        }
        if ($step['name'] === 'Tear down the throwaway stack') {
            expect($step['run'])->toMatch('/if \[ -n "\$REF" \]; then\s+\$REHEARSAL_COMPOSE down -v/');

            continue;
        }
        expect($step['if'] ?? null)->toBe("inputs.ref != ''", $step['name']);
    }
})->group('structure');

it('writes only allowlisted keys to a private env file, read from the throwaway mysql', function (): void {
    $step = restoreDryRunStep('restore-dry-run', 'name', 'Write the rehearsal env file');
    $fake = 'for last; do :; done; printenv "$last"';
    $base = [
        'RESTORE_PROJECT' => 'temari-restore',
        'RESTORE_COMPOSE_FILE' => 'deploy/restore-dry-run-compose.yml',
        'RESTORE_SERVICE' => 'mysql_restore',
        'DB_DATABASE' => 'temari',
        'DB_USERNAME' => 'temari_app',
        'DB_PASSWORD' => 'p$ss #w"rd',
        'APP_KEY' => 'base64:prod-key',
        'REDIS_PASSWORD' => 'redis-secret',
        'STRAVA_CLIENT_SECRET' => 'strava-secret',
        'AZURE_OPENAI_API_KEY' => 'azure-secret',
        'TELEGRAM_BOT_TOKEN' => 'telegram-secret',
        'VAPID_PRIVATE_KEY' => 'vapid-secret',
    ];

    $written = [];
    foreach (['fallback' => ['DB_ANALYTICS_DATABASE' => false], 'custom' => ['DB_ANALYTICS_DATABASE' => 'analytics_db']] as $case => $extra) {
        $file = sys_get_temp_dir().'/temari-rehearsal-'.uniqid();
        [$process, $bin] = runWithFakeDocker($step['run'], $fake, [...$base, ...$extra, 'REHEARSAL_ENV_FILE' => $file]);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        clearstatcache();
        $written[$case] = [(string) file_get_contents($file), fileperms($file) & 0777];
        unlink($file);
        File::deleteDirectory($bin);
    }

    [$content, $mode] = $written['fallback'];
    $lines = explode("\n", trim($content));
    $keys = array_map(fn (string $line): string => strstr($line, '=', true), $lines);

    expect($step['if'])->toBe("inputs.ref != ''")
        ->and($step['env']['REHEARSAL_ENV_FILE'])->toBe('${{ runner.temp }}/rehearsal-app-env')
        ->and($keys)->toBe([
            'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_ANALYTICS_DATABASE', 'APP_KEY',
            'QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER', 'MAIL_MAILER', 'BROADCAST_CONNECTION', 'PULSE_ENABLED',
        ])
        ->and($lines)->toContain("DB_HOST='mysql_restore'")
        ->toContain("DB_DATABASE='temari'")
        ->toContain("DB_USERNAME='temari_app'")
        ->toContain("DB_PASSWORD='p\$ss #w\"rd'")
        ->toContain("DB_ANALYTICS_DATABASE='temari_analytics'")
        ->toContain("QUEUE_CONNECTION='null'")
        ->toContain("CACHE_STORE='array'")
        ->toContain("SESSION_DRIVER='array'")
        ->toContain("MAIL_MAILER='array'")
        ->toContain("BROADCAST_CONNECTION='null'")
        ->toContain("PULSE_ENABLED='false'")
        ->and($content)->toMatch("~^APP_KEY='base64:[A-Za-z0-9+/]{43}='$~m")
        ->not->toContain('prod-key')
        ->not->toContain('secret')
        ->and($mode)->toBe(0600)
        ->and($written['custom'][0])->toContain("DB_ANALYTICS_DATABASE='analytics_db'");
})->group('structure');

it('migrates both sets on the ref image, then fails loudly naming any connection with pending migrations', function (): void {
    $steps = restoreDryRunWorkflow()['jobs']['restore-dry-run']['steps'];
    $names = array_map(fn (array $step): string => $step['name'] ?? $step['uses'] ?? '', $steps);
    $order = array_map(fn (string $name): int|false => array_search($name, $names, true), [
        'Verify the restore',
        "Pull the ref's image",
        'Write the rehearsal env file',
        "Migrate the restore on the ref's image",
        "Migrate the restore's analytics schema",
        'Require no pending migrations on either connection',
        'Tear down the throwaway stack',
    ]);
    $byName = collect($steps)->keyBy(fn (array $step): string => $step['name'] ?? $step['uses']);
    $pending = $byName['Require no pending migrations on either connection'];
    $fake = 'case "$*" in *--database=analytics*) exit "$FAKE_ANALYTICS" ;; *) exit "$FAKE_DEFAULT" ;; esac';
    $env = ['REHEARSAL_COMPOSE' => 'docker compose -p temari-restore'];

    [$clean] = runWithFakeDocker($pending['run'], $fake, [...$env, 'FAKE_DEFAULT' => '0', 'FAKE_ANALYTICS' => '0']);
    [$analytics, $bin] = runWithFakeDocker($pending['run'], $fake, [...$env, 'FAKE_DEFAULT' => '0', 'FAKE_ANALYTICS' => '1']);
    [$default, $bin2] = runWithFakeDocker($pending['run'], $fake, [...$env, 'FAKE_DEFAULT' => '1', 'FAKE_ANALYTICS' => '0']);
    File::deleteDirectory($bin);
    File::deleteDirectory($bin2);

    expect($order)->toBe(array_values(array_filter($order, is_int(...))))
        ->and($order)->toEqual(collect($order)->sort()->values()->all())
        ->and($byName["Migrate the restore on the ref's image"]['run'])->toBe('$REHEARSAL_COMPOSE run --rm --no-deps app_rehearsal php artisan migrate --force')
        ->and($byName["Migrate the restore's analytics schema"]['run'])->toBe('$REHEARSAL_COMPOSE run --rm --no-deps app_rehearsal php artisan migrate --database=analytics --path=database/migrations/analytics --force')
        ->and($byName["Pull the ref's image"]['run'])->toBe('docker pull "$REHEARSAL_IMAGE"')
        ->and(collect([
            "Pull the ref's image" => 15,
            "Migrate the restore on the ref's image" => 10,
            "Migrate the restore's analytics schema" => 10,
            'Require no pending migrations on either connection' => 2,
        ])->every(fn (int $minutes, string $name): bool => $byName[$name]['timeout-minutes'] === $minutes && $byName[$name]['if'] === "inputs.ref != ''"))->toBeTrue()
        ->and($pending['run'])->toContain('migrate:status --pending=1')
        ->toContain('migrate:status --database=analytics --path=database/migrations/analytics --pending=1')
        ->and($clean->getExitCode())->toBe(0, $clean->getOutput())
        ->and($analytics->getExitCode())->toBe(1)
        ->and($analytics->getOutput())->toContain('::error::analytics connection')->not->toContain('::error::default connection')
        ->and($default->getExitCode())->toBe(1)
        ->and($default->getOutput())->toContain('::error::default connection')->not->toContain('::error::analytics connection');
})->group('structure');

it('tears down one-off containers before the stack, then removes the env file and a pulled image that is not latest or previous', function (): void {
    $teardown = restoreDryRunStep('restore-dry-run', 'name', 'Tear down the throwaway stack');
    $cleanup = restoreDryRunStep('restore-dry-run', 'name', 'Remove the rehearsal env file and image');
    $fake = <<<'SH'
        echo "$*" >> "$DOCKER_LOG"
        if [ "$1 $2" = "image inspect" ]; then
          case "$3" in
            temari/app:latest) id="$FAKE_LATEST" ;;
            temari/app:previous) id="$FAKE_PREVIOUS" ;;
            *) id="$FAKE_PULLED" ;;
          esac
          [ -n "$id" ] || exit 1
          echo "$id"
        fi
        SH;
    $image = 'ghcr.io/owner/repo/app:'.str_repeat('b', 40);

    $removed = [];
    foreach ([
        'distinct' => ['sha256:new', 'sha256:live', 'sha256:old'],
        'latest' => ['sha256:live', 'sha256:live', 'sha256:old'],
        'previous' => ['sha256:old', 'sha256:live', 'sha256:old'],
        'absent' => ['', 'sha256:live', 'sha256:old'],
    ] as $case => [$pulled, $latest, $previous]) {
        $envFile = sys_get_temp_dir().'/temari-rehearsal-'.uniqid();
        touch($envFile);
        [$process, $bin] = runWithFakeDocker($cleanup['run'], $fake, [
            'REHEARSAL_IMAGE' => $image,
            'REHEARSAL_ENV_FILE' => $envFile,
            'FAKE_PULLED' => $pulled,
            'FAKE_LATEST' => $latest,
            'FAKE_PREVIOUS' => $previous,
        ]);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_exists($envFile))->toBeFalse();
        $removed[$case] = str_contains((string) @file_get_contents("{$bin}/docker-log"), "rmi {$image}");
        File::deleteDirectory($bin);
    }

    expect($teardown['if'])->toBe('always()')
        ->and(strpos((string) $teardown['run'], 'label=com.docker.compose.oneoff=True'))->toBeLessThan(strpos((string) $teardown['run'], 'down -v'))
        ->and($teardown['run'])->toContain('label=com.docker.compose.project=$RESTORE_PROJECT')
        ->toContain('xargs --no-run-if-empty docker rm -f')
        ->toContain('docker compose -p "$RESTORE_PROJECT" -f "$RESTORE_COMPOSE_FILE" down -v')
        ->toContain('$REF')
        ->toContain('$REHEARSAL_SHA')
        ->toContain('${{ steps.pick.outputs.label }}')
        ->and($cleanup['if'])->toBe("always() && inputs.ref != ''")
        ->and($removed)->toBe(['distinct' => true, 'latest' => false, 'previous' => false, 'absent' => false]);
})->group('structure');
