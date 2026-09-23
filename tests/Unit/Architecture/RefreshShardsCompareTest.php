<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Exercises scripts/compare-shards.php, the decision guard behind
 * .github/workflows/refresh-shards.yml (docs/decisions/sharded-pr-coverage.md).
 * A class add/remove always decides yes; otherwise it decides yes only when the
 * old split, re-measured on the new timings, is both over the 10% threshold
 * and worse than a fresh split of the new timings.
 */
function shardsFixture(array $timings): string
{
    $path = sys_get_temp_dir().'/shards-'.uniqid().'.json';
    file_put_contents($path, json_encode(['timings' => $timings], JSON_THROW_ON_ERROR));

    return $path;
}

function compareShards(array $old, array $new): array
{
    $oldPath = shardsFixture($old);
    $newPath = shardsFixture($new);
    $bodyPath = sys_get_temp_dir().'/shards-body-'.uniqid().'.md';

    try {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/compare-shards.php'),
            "--old={$oldPath}",
            "--new={$newPath}",
            "--body-out={$bodyPath}",
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $output = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            [$key, $value] = explode('=', $line, 2);
            $output[$key] = $value;
        }

        $output['body'] = file_exists($bodyPath) ? file_get_contents($bodyPath) : null;

        return $output;
    } finally {
        @unlink($oldPath);
        @unlink($newPath);
        @unlink($bodyPath);
    }
}

const BALANCED_BASE = [
    'Tests\\A' => 10.0,
    'Tests\\B' => 10.0,
    'Tests\\C' => 10.0,
    'Tests\\D' => 10.0,
    'Tests\\E' => 10.0,
    'Tests\\F' => 10.0,
];

it('decides no when the map is unchanged', function (): void {
    $out = compareShards(BALANCED_BASE, BALANCED_BASE);

    expect($out['decision'])->toBe('no')
        ->and($out['added'])->toBe('0')
        ->and($out['removed'])->toBe('0');
});

it('decides yes when a class was added', function (): void {
    $out = compareShards(BALANCED_BASE, [...BALANCED_BASE, 'Tests\\G' => 10.0]);

    expect($out['decision'])->toBe('yes')
        ->and($out['added'])->toBe('1')
        ->and($out['removed'])->toBe('0')
        ->and($out['body'])->toContain('Tests\\G')
        ->toContain('GITHUB_TOKEN');
});

it('decides yes when a class was removed', function (): void {
    $out = compareShards([...BALANCED_BASE, 'Tests\\G' => 10.0], BALANCED_BASE);

    expect($out['decision'])->toBe('yes')
        ->and($out['added'])->toBe('0')
        ->and($out['removed'])->toBe('1')
        ->and($out['body'])->toContain('Tests\\G');
});

// The old map's own split ends up as Tests\A+D, Tests\B+E, Tests\C+F once
// timings tie-break in insertion order (see partitionByTime).
it('decides yes when a fresh split would rebalance shards the stale one has drifted out of', function (): void {
    $out = compareShards(BALANCED_BASE, [
        'Tests\\A' => 11.2, 'Tests\\B' => 10.0, 'Tests\\C' => 8.8,
        'Tests\\D' => 11.2, 'Tests\\E' => 10.0, 'Tests\\F' => 8.8,
    ]);

    // Under the pre-fix rule this looked at new_excess_pct (0.0, not > 10) and
    // decided no, even though the stale split is 12% over mean right now.
    expect($out['decision'])->toBe('yes')
        ->and((float) $out['old_applied_excess_pct'])->toBe(12.0)
        ->and((float) $out['new_excess_pct'])->toBe(0.0);
});

it('decides no when the stale split drifts but stays within the 10% threshold', function (): void {
    $out = compareShards(BALANCED_BASE, [
        'Tests\\A' => 10.8, 'Tests\\B' => 10.0, 'Tests\\C' => 9.2,
        'Tests\\D' => 10.8, 'Tests\\E' => 10.0, 'Tests\\F' => 9.2,
    ]);

    expect($out['decision'])->toBe('no')
        ->and((float) $out['old_applied_excess_pct'])->toBe(8.0);
});

it('decides no when a fresh split would not do any better than the stale one', function (): void {
    $out = compareShards(
        ['Tests\\A' => 100.0, 'Tests\\B' => 47.0, 'Tests\\C' => 47.0, 'Tests\\D' => 47.0, 'Tests\\E' => 47.0],
        ['Tests\\A' => 112.0, 'Tests\\B' => 47.0, 'Tests\\C' => 47.0, 'Tests\\D' => 47.0, 'Tests\\E' => 47.0],
    );

    expect($out['decision'])->toBe('no')
        ->and((float) $out['old_applied_excess_pct'])->toBe(12.0)
        ->and((float) $out['new_excess_pct'])->toBe(12.0);
});

it('runs the refresh workflow on ubuntu-latest, serially, off the existing nightly crons', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/refresh-shards.yml'));
    $job = $workflow['jobs']['refresh-shards'];

    expect($job['runs-on'])->toBe('ubuntu-latest')
        ->and($workflow['permissions'])->toBe(['contents' => 'write', 'pull-requests' => 'write']);

    $regenerate = collect($job['steps'])->firstWhere('name', 'Regenerate shards.json');

    expect($regenerate['run'])
        ->toContain('--update-shards')
        ->toContain('--exclude-group=structure')
        ->toContain('--compact')
        ->not->toContain('--parallel');

    $cron = (string) $workflow['on']['schedule'][0]['cron'];
    $minute = explode(' ', $cron)[0];

    expect((int) $minute)->not->toBe(0)
        ->and($cron)->not->toBe('23 21 * * *')
        ->toBe('5 19 * * *');
});

it('never enables auto-merge on the refresh PR', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/refresh-shards.yml'));

    expect($workflow)->not->toContain('--auto')
        ->not->toContain('enable-auto-merge')
        ->toContain('GITHUB_TOKEN');
});

it('commits the refreshed map without the local developer git hooks', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/refresh-shards.yml'));

    expect($workflow)->toContain('git -c core.hooksPath=/dev/null commit')
        ->not->toMatch('/^\s*git commit /m');
});
