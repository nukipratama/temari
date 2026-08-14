#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Doc citation guard.
 *
 * Fails if any code citation in docs/ points at a path that no longer exists —
 * the most dangerous form of doc rot ("the doc references code that's gone") —
 * or, for a `#L42` citation, if the symbol the doc names has moved away from
 * the line it cites. A path-only check passes happily while every line number
 * in the file rots, which is how citations end up hundreds of lines off.
 *
 * Checks, per docs/**.md (excluding .obsidian/ and underscore-prefixed files like _template.md):
 *   - frontmatter `code_refs:` list items
 *   - inline markdown link targets, e.g. [text](app/Services/Foo.php#L42)
 *   - line drift for `#L42` targets, when the citation names a symbol we can find
 * Skips: external URLs, mailto, pure anchors, and [[wikilinks]] (unresolved Obsidian
 * links are allowed — they mark planned notes).
 *
 * Standalone: no Laravel boot. Run from anywhere: `php scripts/check-doc-citations.php`.
 */

$root = dirname(__DIR__);
$docsDir = $root.'/docs';

if (! is_dir($docsDir)) {
    fwrite(STDERR, "docs/ not found at {$docsDir}\n");
    exit(1);
}

/**
 * How far a cited line may sit from the symbol it names before it counts as
 * drift. Citing a docblock's opening line rather than the declaration it
 * documents is a convention here, not an error, and docblocks run long; real
 * drift is hundreds of lines, so a generous window costs nothing.
 */
const LINE_DRIFT_TOLERANCE = 15;

/** @var list<string> $missing */
$missing = [];

/** @var list<string> $drifted */
$drifted = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($docsDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'md') {
        continue;
    }

    $path = $file->getPathname();

    if (str_contains($path, '/.obsidian/') || str_starts_with($file->getBasename(), '_')) {
        continue;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $inCodeRefs = false;
    $inFrontmatter = false;
    $frontmatterDone = false;

    foreach ($lines as $index => $line) {
        $lineNo = $index + 1;

        // Track the YAML frontmatter fence so `code_refs:` is only honored there,
        // not when it appears as body prose (e.g. a doc explaining this convention).
        if (! $frontmatterDone && preg_match('/^---\s*$/', $line) === 1) {
            if ($inFrontmatter) {
                $inFrontmatter = false;
                $frontmatterDone = true;
                $inCodeRefs = false;
            } else {
                $inFrontmatter = true;
            }

            continue;
        }

        if ($inFrontmatter && preg_match('/^code_refs:/', $line) === 1) {
            $inCodeRefs = true;

            continue;
        }

        if ($inCodeRefs) {
            // A YAML list item requires a space after the dash.
            if (preg_match('/^\s*-\s+(.+?)\s*$/', $line, $m) === 1) {
                checkCitation($root, $path, $lineNo, $m[1], $missing);

                continue;
            }

            // A non-comment, non-list line ends the code_refs block.
            if (preg_match('/^\s*#/', $line) !== 1) {
                $inCodeRefs = false;
            }
        }

        if (preg_match_all('/\[([^\]]*)\]\(([^)]+)\)/', $line, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $match) {
                checkCitation($root, $path, $lineNo, $match[2], $missing);
                checkLineDrift($root, $path, $lineNo, $match[2], $match[1], $drifted);
            }
        }
    }
}

if ($missing !== []) {
    fwrite(STDERR, "Doc citation guard: these citations point at paths that no longer exist:\n");
    foreach ($missing as $entry) {
        fwrite(STDERR, "  {$entry}\n");
    }
    fwrite(STDERR, "\nFix the citation or update the doc — docs must point at real code.\n");
    exit(1);
}

if ($drifted !== []) {
    fwrite(STDERR, "Doc citation guard: these #L citations name a symbol that has moved:\n");
    foreach ($drifted as $entry) {
        fwrite(STDERR, "  {$entry}\n");
    }
    fwrite(STDERR, "\nUpdate the line number to where the symbol lives now.\n");
    exit(1);
}

echo "Doc citation guard: all citations resolve ✓\n";
exit(0);

/**
 * @param  list<string>  $missing
 */
function checkCitation(string $root, string $doc, int $lineNo, string $raw, array &$missing): void
{
    $candidate = trim($raw);

    // Drop a markdown link title: [text](path "title").
    $candidate = preg_split('/\s+/', $candidate)[0] ?? '';

    // Drop a #L42 / #anchor suffix.
    $candidate = (string) preg_replace('/#.*$/', '', $candidate);
    $candidate = trim($candidate);

    if ($candidate === '') {
        return;
    }

    // Skip URLs, mail, protocol-relative, and pure anchors.
    if (preg_match('~^(https?:|mailto:|//|#)~', $candidate) === 1) {
        return;
    }

    $relativeToRoot = $root.'/'.ltrim($candidate, '/');
    $relativeToDoc = dirname($doc).'/'.$candidate;

    if (file_exists($relativeToRoot) || file_exists($relativeToDoc)) {
        return;
    }

    $docRelative = substr($doc, strlen($root) + 1);
    $missing[] = "{$docRelative}:{$lineNo} -> {$candidate}";
}

/**
 * Verify that a `#L42` citation still lands near the symbol the doc names.
 *
 * Symbol candidates come from the link text alone — `[ChainResolver::isHead()](…#L20)`
 * names what it points at. Widening to the surrounding prose sweeps up identifiers
 * belonging to the *other* citations on the same line and turns the guard into a
 * false-alarm generator, which gets it switched off. A citation whose link text
 * carries no symbol (`[Analysis.php:116](…#L116)`) is simply not checked, and a
 * candidate that appears nowhere in the target file is dropped rather than
 * reported. Only a symbol that demonstrably lives elsewhere in the same file
 * counts as drift.
 *
 * @param  list<string>  $drifted
 */
function checkLineDrift(string $root, string $doc, int $lineNo, string $target, string $linkText, array &$drifted): void
{
    if (preg_match('/^([^#\s]+)#L(\d+)$/', trim($target), $m) !== 1) {
        return;
    }

    [, $path, $citedLine] = $m;
    $citedLine = (int) $citedLine;

    $file = $root.'/'.ltrim($path, '/');
    if (! is_file($file)) {
        return;
    }

    $source = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $docRelative = substr($doc, strlen($root) + 1);

    if ($citedLine > count($source)) {
        $drifted[] = "{$docRelative}:{$lineNo} -> {$path}#L{$citedLine} (file has only ".count($source).' lines)';

        return;
    }

    $candidates = symbolCandidates($linkText, basename($path));
    if ($candidates === []) {
        return;
    }

    /** @var array<string, list<int>> $found */
    $found = [];
    foreach ($candidates as $symbol) {
        $hits = [];
        foreach ($source as $index => $text) {
            if (preg_match('/\b'.preg_quote($symbol, '/').'\b/', $text) === 1) {
                $hits[] = $index + 1;
            }
        }
        if ($hits !== []) {
            $found[$symbol] = $hits;
        }
    }

    if ($found === []) {
        return;
    }

    foreach ($found as $hits) {
        foreach ($hits as $hit) {
            if (abs($hit - $citedLine) <= LINE_DRIFT_TOLERANCE) {
                return;
            }
        }
    }

    $report = [];
    foreach ($found as $symbol => $hits) {
        $report[] = $symbol.' at L'.implode('/L', array_slice($hits, 0, 3));
    }

    $drifted[] = "{$docRelative}:{$lineNo} -> {$path}#L{$citedLine} (".implode('; ', $report).')';
}

/**
 * Identifiers in the link text that are plausibly symbols: camelCase or
 * snake_case, at least four characters, and not the cited file's own basename.
 *
 * PascalCase is deliberately excluded. A link text is very often just the class
 * name (`[StreamAnalysis](app/…/StreamAnalysis.php#L182)`), which matches the
 * file's own declaration near line 1 and would flag every citation deeper in the
 * file. A method or property name is what actually pins a line.
 *
 * @return list<string>
 */
function symbolCandidates(string $linkText, string $basename): array
{
    $stem = preg_replace('/\.[^.]+$/', '', $basename);
    $candidates = [];

    // `Class::member` names its member unambiguously, so take it whatever its
    // shape — this is the only way an all-lowercase name like `::show` is
    // distinguishable from an English word.
    if (preg_match_all('/::([A-Za-z_][A-Za-z0-9_]*)/', $linkText, $members) > 0) {
        foreach ($members[1] as $member) {
            $candidates[$member] = true;
        }
    }

    if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]{3,}/', $linkText, $words) === 0) {
        return array_keys($candidates);
    }

    foreach ($words[0] as $word) {
        if ($word === $stem || $word === $basename) {
            continue;
        }

        // camelCase, snake_case or a SCREAMING_SNAKE constant. A bare lowercase
        // English word in prose is not a symbol, and a PascalCase one is usually
        // the class itself, which sits near line 1 of its own file.
        if (preg_match('/^[a-z][A-Za-z0-9]*[A-Z]|^[a-z0-9]+_[a-z0-9_]+$|^[A-Z0-9]+_[A-Z0-9_]+$/', $word) !== 1) {
            continue;
        }

        $candidates[$word] = true;
    }

    return array_keys($candidates);
}
