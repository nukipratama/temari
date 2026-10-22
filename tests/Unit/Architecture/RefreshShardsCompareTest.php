<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** @param  array<string, float>  $timings */
function shardsFixture(array $timings): string
{
    $path = sys_get_temp_dir().'/shards-'.uniqid().'.json';
    file_put_contents($path, json_encode(['timings' => $timings], JSON_THROW_ON_ERROR));

    return $path;
}

/**
 * @param  array<string, float>  $old
 * @param  array<string, float>  $new
 * @return array<string, string|false|null>
 */
function compareShards(array $old, array $new, ?int $shards = 3): array
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
            ...($shards === null ? [] : ["--shards={$shards}"]),
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
    'Tests\\G' => 10.0,
    'Tests\\H' => 10.0,
    'Tests\\I' => 10.0,
];

/**
 * Exercises scripts/compare-shards.php, the decision guard behind
 * .github/workflows/upkeep-refresh-shards.yml (docs/decisions/sharded-pr-coverage.md).
 * A class add/remove always decides yes; otherwise it decides yes only when the
 * old split, re-measured on the new timings, is both over the 10% threshold
 * and worse than a fresh split of the new timings.
 */
it('decides no when the map is unchanged', function (): void {
    $out = compareShards(BALANCED_BASE, BALANCED_BASE);

    expect($out['decision'])->toBe('no')
        ->and($out['added'])->toBe('0')
        ->and($out['removed'])->toBe('0');
});

it('decides yes when a class was added', function (): void {
    $out = compareShards(BALANCED_BASE, [...BALANCED_BASE, 'Tests\\J' => 10.0]);

    expect($out['decision'])->toBe('yes')
        ->and($out['added'])->toBe('1')
        ->and($out['removed'])->toBe('0')
        ->and($out['body'])->toContain('Tests\\J');
});

it('decides yes when a class was removed', function (): void {
    $out = compareShards([...BALANCED_BASE, 'Tests\\J' => 10.0], BALANCED_BASE);

    expect($out['decision'])->toBe('yes')
        ->and($out['added'])->toBe('0')
        ->and($out['removed'])->toBe('1')
        ->and($out['body'])->toContain('Tests\\J');
});

// The old map's own split ends up as Tests\A+D+G, Tests\B+E+H and Tests\C+F+I
// once timings tie-break in insertion order (see partitionByTime).
it('decides yes when a fresh split would rebalance shards the stale one has drifted out of', function (): void {
    $out = compareShards(BALANCED_BASE, [
        'Tests\\A' => 11.2, 'Tests\\B' => 10.0, 'Tests\\C' => 8.8,
        'Tests\\D' => 11.2, 'Tests\\E' => 10.0, 'Tests\\F' => 8.8,
        'Tests\\G' => 11.2, 'Tests\\H' => 10.0, 'Tests\\I' => 8.8,
    ]);

    // The fresh split is balanced (new_excess_pct 0.0), but the stale split is 12% over mean right now.
    expect($out['decision'])->toBe('yes')
        ->and((float) $out['old_applied_excess_pct'])->toBe(12.0)
        ->and((float) $out['new_excess_pct'])->toBe(0.0);
});

it('decides no when the stale split drifts but stays within the 10% threshold', function (): void {
    $out = compareShards(BALANCED_BASE, [
        'Tests\\A' => 10.8, 'Tests\\B' => 10.0, 'Tests\\C' => 9.2,
        'Tests\\D' => 10.8, 'Tests\\E' => 10.0, 'Tests\\F' => 9.2,
        'Tests\\G' => 10.8, 'Tests\\H' => 10.0, 'Tests\\I' => 9.2,
    ]);

    expect($out['decision'])->toBe('no')
        ->and((float) $out['old_applied_excess_pct'])->toBe(8.0);
});

it('decides no when a fresh split would not do any better than the stale one', function (): void {
    $rest = ['Tests\\B' => 50.0, 'Tests\\C' => 50.0, 'Tests\\D' => 50.0, 'Tests\\E' => 50.0];
    $out = compareShards(['Tests\\A' => 100.0, ...$rest], ['Tests\\A' => 120.0, ...$rest]);

    expect($out['decision'])->toBe('no')
        ->and((float) $out['old_applied_excess_pct'])->toBe(12.5)
        ->and((float) $out['new_excess_pct'])->toBe(12.5);
});

it('simulates the same number of shards backend CI runs', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/ci-backend.yml'));
    preg_match('/^const SHARD_TOTAL = (\d+);$/m', (string) file_get_contents(base_path('scripts/compare-shards.php')), $constant);

    $body = compareShards(BALANCED_BASE, BALANCED_BASE, shards: null)['body'];

    expect(data_get($workflow, 'jobs.tests.strategy.matrix.shard'))->toBe([1, 2, 3, 4])
        ->and((int) ($constant[1] ?? 0))->toBe(4)
        ->and($body)->toContain('Simulated 4-shard totals')
        ->toContain('| shard 4 |')
        ->and($body)->not->toContain('| shard 5 |');
});

it('times the shard map with pcov loaded, as PR shards run', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/upkeep-refresh-shards.yml'));
    $steps = collect(arrayOf(data_get($workflow, 'jobs.refresh-shards.steps')));

    expect(data_get($steps->firstWhere('uses', './.github/actions/setup-php'), 'with.coverage'))->toBe('pcov')
        ->and(data_get($steps->firstWhere('name', 'Regenerate shards.json'), 'run'))->toContain('--coverage-php=/dev/null');
});

it('runs the refresh workflow on ubuntu-26.04-arm, serially, weekly, off the existing nightly crons', function (): void {
    $workflow = Yaml::parseFile(base_path('.github/workflows/upkeep-refresh-shards.yml'));
    $job = data_get($workflow, 'jobs.refresh-shards');

    expect(data_get($job, 'runs-on'))->toBe('ubuntu-26.04-arm')
        ->and(data_get($workflow, 'permissions'))->toBe(['contents' => 'write', 'pull-requests' => 'write']);

    $regenerate = collect(arrayOf(data_get($job, 'steps')))->firstWhere('name', 'Regenerate shards.json');

    expect(data_get($regenerate, 'run'))
        ->toContain('--update-shards')
        ->toContain('--exclude-group=structure')
        ->toContain('--compact')
        ->and(data_get($regenerate, 'run'))->not->toContain('--parallel');

    $schedule = data_get($workflow, 'on.schedule.0.cron');
    $cron = is_string($schedule) ? $schedule : throw new InvalidArgumentException('The refresh cron is not a string.');
    $minute = explode(' ', $cron)[0];

    expect((int) $minute)->not->toBe(0)
        ->and($cron)->not->toBe('23 21 * * *')
        ->toBe('5 19 * * 0');
});

it('never enables auto-merge on the refresh PR', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/upkeep-refresh-shards.yml'));

    expect($workflow)->not->toContain('--auto')
        ->and($workflow)->not->toContain('enable-auto-merge')
        ->toContain('GITHUB_TOKEN');
});

it('commits the refreshed map without the local developer git hooks', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/upkeep-refresh-shards.yml'));

    expect($workflow)->toContain('git -c core.hooksPath=/dev/null commit')
        ->and($workflow)->not->toMatch('/^\s*git commit /m');
});
