#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Shard-refresh decision guard.
 *
 * Compares an old and a new tests/.pest/shards.json timing map and decides
 * whether the drift is worth a PR. Mirrors Pest's own LPT bin-packing
 * (vendor/pestphp/pest/src/Plugins/Shard.php::partitionByTime) so the
 * simulated splits here match what --shard actually does.
 *
 * Decides yes when a class was added or removed, or when the new timings'
 * own 3-shard split still has its slowest shard more than 10% over the mean
 * while sticking with the old split (re-measured on the new timings) would
 * be worse.
 *
 * Usage: php scripts/compare-shards.php --old=<path> --new=<path> [--body-out=<path>]
 * Prints GITHUB_OUTPUT-format lines to stdout: decision, added, removed,
 * new_excess_pct, old_applied_excess_pct, summary.
 */

const SHARD_TOTAL = 3;
const THRESHOLD_PCT = 10.0;

/**
 * @return array<string, float>
 */
function readTimings(string $path): array
{
    $raw = file_get_contents($path);

    if ($raw === false) {
        fwrite(STDERR, "Could not read {$path}\n");
        exit(1);
    }

    $data = json_decode($raw, true);

    if (! is_array($data) || ! isset($data['timings']) || ! is_array($data['timings'])) {
        fwrite(STDERR, "{$path} is not a valid shards.json (missing a 'timings' map)\n");
        exit(1);
    }

    /** @var array<string, float> */
    return $data['timings'];
}

/**
 * @param  list<float>  $values
 */
function median(array $values): float
{
    if ($values === []) {
        return 1.0;
    }

    sort($values);
    $count = count($values);
    $middle = (int) floor($count / 2);

    if ($count % 2 === 0) {
        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    return $values[$middle];
}

/**
 * Pest's LPT (Longest Processing Time) bin-packing: sort tests by time
 * descending (median for a class with no timing), then greedily assign each
 * to the bin currently carrying the least total time.
 *
 * @param  list<string>  $tests
 * @param  array<string, float>  $timings
 * @return list<list<string>>
 */
function partitionByTime(array $tests, array $timings, int $total): array
{
    $known = array_values(array_filter(
        array_map(fn (string $t): ?float => $timings[$t] ?? null, $tests),
        fn (?float $v): bool => $v !== null,
    ));
    $med = median($known);

    $withTimes = array_map(
        fn (string $t): array => ['test' => $t, 'time' => $timings[$t] ?? $med],
        $tests,
    );

    usort($withTimes, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

    /** @var list<list<string>> $bins */
    $bins = array_fill(0, $total, []);
    /** @var list<float> $binTimes */
    $binTimes = array_fill(0, $total, 0.0);

    foreach ($withTimes as $item) {
        $minIndex = array_search(min($binTimes), $binTimes, true);
        assert(is_int($minIndex));

        $bins[$minIndex][] = $item['test'];
        $binTimes[$minIndex] += $item['time'];
    }

    return $bins;
}

/**
 * @param  list<list<string>>  $bins
 * @param  array<string, float>  $timings
 * @return list<float>
 */
function binTotals(array $bins, array $timings): array
{
    return array_map(
        fn (array $bin): float => array_sum(array_map(fn (string $t): float => $timings[$t] ?? 0.0, $bin)),
        $bins,
    );
}

/**
 * @param  list<float>  $totals
 */
function excessPct(array $totals): float
{
    if ($totals === []) {
        return 0.0;
    }

    $mean = array_sum($totals) / count($totals);

    if ($mean <= 0.0) {
        return 0.0;
    }

    return (max($totals) - $mean) / $mean * 100;
}

/**
 * @param  list<string>  $added
 * @param  list<string>  $removed
 * @param  list<float>  $newTotals
 * @param  list<float>  $oldAppliedTotals
 */
function buildPrBody(array $added, array $removed, array $newTotals, array $oldAppliedTotals, float $newExcess, float $oldAppliedExcess): string
{
    $lines = [];
    $lines[] = 'Nightly regeneration of `tests/.pest/shards.json` via `--update-shards --exclude-group=structure`.';
    $lines[] = '';
    $lines[] = '## Class diff';

    if ($added === [] && $removed === []) {
        $lines[] = '- No classes added or removed.';
    } else {
        foreach ($added as $class) {
            $lines[] = "- + `{$class}`";
        }
        foreach ($removed as $class) {
            $lines[] = "- - `{$class}`";
        }
    }

    $lines[] = '';
    $lines[] = '## Simulated 3-shard totals (seconds)';
    $lines[] = '| | New split | Old split, new timings |';
    $lines[] = '| --- | --- | --- |';

    $newSorted = $newTotals;
    $oldSorted = $oldAppliedTotals;
    rsort($newSorted);
    rsort($oldSorted);

    for ($i = 0; $i < SHARD_TOTAL; $i++) {
        $lines[] = sprintf(
            '| shard %d | %.2f | %.2f |',
            $i + 1,
            $newSorted[$i] ?? 0.0,
            $oldSorted[$i] ?? 0.0,
        );
    }

    $lines[] = sprintf(
        'Slowest shard over mean: new split %.1f%%, old split on new timings %.1f%%.',
        $newExcess,
        $oldAppliedExcess,
    );

    $lines[] = '';
    $lines[] = '## Before merging';
    $lines[] = 'This PR was opened with the default `GITHUB_TOKEN`, so its own CI did not start '.
        "automatically — GitHub's recursion guard blocks workflows from triggering on a ".
        '`GITHUB_TOKEN` push. Re-run CI manually from the Checks tab, or push an empty commit, before merging.';

    return implode("\n", $lines)."\n";
}

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
        $args[$m[1]] = $m[2];
    }
}

if (! isset($args['old'], $args['new'])) {
    fwrite(STDERR, "Usage: php scripts/compare-shards.php --old=<path> --new=<path> [--body-out=<path>]\n");
    exit(1);
}

$oldTimings = readTimings($args['old']);
$newTimings = readTimings($args['new']);

$oldClasses = array_keys($oldTimings);
$newClasses = array_keys($newTimings);

$added = array_values(array_diff($newClasses, $oldClasses));
$removed = array_values(array_diff($oldClasses, $newClasses));
sort($added);
sort($removed);

$newBins = partitionByTime($newClasses, $newTimings, SHARD_TOTAL);
$newTotals = binTotals($newBins, $newTimings);
$newExcess = excessPct($newTotals);

// The old map's own split, re-measured against the new timings, restricted
// to classes that still exist — a class the old map never saw was never
// assigned by it, so it cannot count toward "the old split".
$oldBins = partitionByTime($oldClasses, $oldTimings, SHARD_TOTAL);
$oldBinsSurviving = array_map(
    fn (array $bin): array => array_values(array_intersect($bin, $newClasses)),
    $oldBins,
);
$oldAppliedTotals = binTotals($oldBinsSurviving, $newTimings);
$oldAppliedExcess = excessPct($oldAppliedTotals);

$classesChanged = $added !== [] || $removed !== [];
$balanceWorsened = $newExcess > THRESHOLD_PCT && $oldAppliedExcess > $newExcess;
$decision = $classesChanged || $balanceWorsened;

$summary = $decision
    ? sprintf(
        'Shard refresh: opening/updating the PR (%d added, %d removed, slowest shard %.1f%% over mean vs %.1f%% unrefreshed).',
        count($added),
        count($removed),
        $newExcess,
        $oldAppliedExcess,
    )
    : sprintf(
        'Shard refresh: no PR needed (0 classes added/removed, slowest shard %.1f%% over mean, %.1f%% unrefreshed).',
        $newExcess,
        $oldAppliedExcess,
    );

if (isset($args['body-out'])) {
    file_put_contents($args['body-out'], buildPrBody($added, $removed, $newTotals, $oldAppliedTotals, $newExcess, $oldAppliedExcess));
}

echo 'decision='.($decision ? 'yes' : 'no')."\n";
echo 'added='.count($added)."\n";
echo 'removed='.count($removed)."\n";
echo 'new_excess_pct='.number_format($newExcess, 2)."\n";
echo 'old_applied_excess_pct='.number_format($oldAppliedExcess, 2)."\n";
echo 'summary='.$summary."\n";
