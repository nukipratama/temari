#!/usr/bin/env php
<?php

declare(strict_types=1);

const CONTRACT_MARKER = '@contract-migration';

/** @return never */
function usage(): void
{
    fwrite(STDERR, "Usage: php scripts/check-migration-safety.php --base <git-ref> | --files <path>...\n");
    exit(2);
}

/** @return list<string> */
function changedMigrationFiles(string $base): array
{
    $command = sprintf(
        'git diff --name-only --diff-filter=ACMR %s HEAD -- database/migrations database/migrations/analytics',
        escapeshellarg($base),
    );
    exec($command, $files, $status);

    if ($status !== 0) {
        fwrite(STDERR, "migration-safety: unable to diff against {$base}\n");
        exit(2);
    }

    return array_values(array_filter($files, static fn (string $file): bool => str_ends_with($file, '.php')));
}

function methodBody(string $source, string $method): ?string
{
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }

        $name = null;
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_STRING) {
                $name = $tokens[$cursor][1];
                break;
            }

            if ($tokens[$cursor] === '(') {
                break;
            }
        }

        if ($name !== $method) {
            continue;
        }

        while ($cursor < $count && $tokens[$cursor] !== '{') {
            $cursor++;
        }

        if ($cursor === $count) {
            return null;
        }

        $depth = 0;
        $body = '';
        for (; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $body;
                }
            }

            $body .= $text;
        }
    }

    return null;
}

/** @return list<string> */
function destructiveOperations(string $body): array
{
    $patterns = [
        'drop operation' => '/(?:->|::)\s*drop[A-Za-z0-9_]*\s*\(/',
        'rename operation' => '/(?:->|::)\s*rename(?:Column)?\s*\(/',
        'column change' => '/->\s*change\s*\(/',
        'data deletion' => '/->\s*(?:delete|truncate)\s*\(/',
        'destructive raw SQL' => '/(?:statement|unprepared)\s*\([^;]*(?:DROP|RENAME|TRUNCATE|ALTER\s+TABLE)/is',
    ];
    $found = [];

    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $body) === 1) {
            $found[] = $label;
        }
    }

    return $found;
}

function hasContractMarker(string $source): bool
{
    return preg_match(
        '#/\*\*(?:(?!\*/)[\s\S])*'.CONTRACT_MARKER.'(?:(?!\*/)[\s\S])*\*/\s*public\s+function\s+up\s*\(#',
        $source,
    ) === 1;
}

$arguments = array_slice($argv, 1);
$mode = array_shift($arguments);

if ($mode === '--base' && count($arguments) === 1) {
    $files = changedMigrationFiles($arguments[0]);
} elseif ($mode === '--files' && $arguments !== []) {
    $files = $arguments;
} else {
    usage();
}

$failed = false;

foreach ($files as $file) {
    if (! is_file($file)) {
        fwrite(STDERR, "migration-safety: file not found: {$file}\n");
        $failed = true;
        continue;
    }

    $source = file_get_contents($file);
    if ($source === false) {
        fwrite(STDERR, "migration-safety: unable to read: {$file}\n");
        $failed = true;
        continue;
    }

    $up = methodBody($source, 'up');
    if ($up === null) {
        fwrite(STDERR, "migration-safety: no up() method found: {$file}\n");
        $failed = true;
        continue;
    }

    $operations = destructiveOperations($up);
    if ($operations === [] || hasContractMarker($source)) {
        continue;
    }

    fwrite(STDERR, sprintf(
        "migration-safety: destructive migration operation in %s (%s); split it into a later contract deploy or mark that reviewed contract step with /** %s */ above up().\n",
        $file,
        implode(', ', $operations),
        CONTRACT_MARKER,
    ));
    $failed = true;
}

exit($failed ? 1 : 0);
