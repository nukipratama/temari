#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Changed-lines coverage guard.
 *
 * Fails when fewer than --min percent of the executable lines a PR adds or
 * modifies under app/**.php are covered in a Clover report. Only lines the
 * Clover lists as statements count; comments, signatures and blank lines are
 * ignored, and a diff with no executable app/ lines passes.
 *
 * Usage: php scripts/check-changed-lines-coverage.php --base=<git-ref> --clover=<path> --min=<percent>
 */

/** @return never */
function usage(): void
{
    fwrite(STDERR, "Usage: php scripts/check-changed-lines-coverage.php --base=<git-ref> --clover=<path> --min=<percent>\n");
    exit(2);
}

/** @return array<string, list<int>> repo-relative path => changed line numbers */
function changedAppLines(string $base): array
{
    $command = sprintf(
        'git diff --unified=0 --find-renames --diff-filter=ACMR %s HEAD -- app',
        escapeshellarg($base),
    );
    exec($command, $diff, $status);

    if ($status !== 0) {
        fwrite(STDERR, "changed-lines: unable to diff against {$base}\n");
        exit(2);
    }

    $changed = [];
    $file = null;

    foreach ($diff as $line) {
        if (str_starts_with($line, '+++ ')) {
            $file = preg_match('#^\+\+\+ b/(app/.+\.php)$#', $line, $match) === 1 ? $match[1] : null;
        } elseif ($file !== null && preg_match('/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/', $line, $match) === 1) {
            $count = isset($match[2]) ? (int) $match[2] : 1;
            $changed[$file] = [...($changed[$file] ?? []), ...($count === 0 ? [] : range((int) $match[1], (int) $match[1] + $count - 1))];
        }
    }

    return $changed;
}

/** @param array<string, list<int>> $changed */
function repoRelativePath(string $cloverPath, array $changed): ?string
{
    for ($offset = strpos($cloverPath, '/'); $offset !== false; $offset = strpos($cloverPath, '/', $offset + 1)) {
        $candidate = substr($cloverPath, $offset + 1);

        if (isset($changed[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

/**
 * @param  array<string, list<int>>  $changed
 * @return array<string, array<int, int>> repo-relative path => statement line => hit count
 */
function statementHits(string $clover, array $changed): array
{
    $xml = @simplexml_load_file($clover);

    if ($xml === false) {
        fwrite(STDERR, "changed-lines: unable to read clover report {$clover}\n");
        exit(2);
    }

    $hits = [];

    foreach ($xml->xpath('//file') ?: [] as $file) {
        $path = repoRelativePath((string) $file['name'], $changed);

        if ($path === null) {
            continue;
        }

        foreach ($file->line as $line) {
            if ((string) $line['type'] === 'stmt') {
                $hits[$path][(int) $line['num']] = (int) $line['count'];
            }
        }
    }

    return $hits;
}

$options = getopt('', ['base:', 'clover:', 'min:']);
$base = $options['base'] ?? null;
$clover = $options['clover'] ?? null;
$min = $options['min'] ?? null;

if (! is_string($base) || ! is_string($clover) || ! is_numeric($min)) {
    usage();
}

$min = (float) $min;
$changed = changedAppLines($base);
$hits = statementHits($clover, $changed);

$total = 0;
$uncovered = [];

foreach ($changed as $path => $lines) {
    foreach (array_unique($lines) as $number) {
        if (! isset($hits[$path][$number])) {
            continue;
        }

        $total++;

        if ($hits[$path][$number] === 0) {
            $uncovered[] = "{$path}:{$number}";
        }
    }
}

$covered = $total - count($uncovered);
$pct = $total > 0 ? $covered * 100 / $total : 100.0;

printf("Changed-lines coverage: %.1f%% (%d/%d executable lines changed under app/), minimum %.1f%%\n", $pct, $covered, $total, $min);

if ($pct < $min) {
    echo "Uncovered changed lines:\n";
    foreach ($uncovered as $line) {
        echo "  {$line}\n";
    }
    fwrite(STDERR, sprintf("FAIL: changed-lines coverage %.1f%% is below the required %.1f%%.\n", floor($pct * 10) / 10, $min));
    exit(1);
}
