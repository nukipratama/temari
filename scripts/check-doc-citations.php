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

        if (preg_match_all('/\[([^\]]*)\]\(([^)]+)\)/', $line, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($all as $match) {
                $linkStart = $match[0][1];
                $linkText = $match[1][0];
                $target = $match[2][0];
                checkCitation($root, $path, $lineNo, $target, $missing);
                checkLineDrift($root, $path, $lineNo, $target, resolveSymbolSource($line, $linkStart, $linkText), $drifted);
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
 * Verify that a `#L42` (or bare `:42` / `path:42`) citation still lands near
 * the symbol the doc names.
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
    $path = trim((string) preg_replace('/#.*$/', '', trim($target)));
    $citedLine = citedLineNumber($target, $linkText);

    if ($path === '' || $citedLine === null) {
        return;
    }

    // Same dual resolution checkCitation() uses: docs cite either root-relative
    // or doc-relative. Resolving only against the root silently skipped every
    // `../../app/...` citation here while the existence check passed them.
    $file = $root.'/'.ltrim($path, '/');
    if (! is_file($file)) {
        $file = dirname($doc).'/'.$path;
    }
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
 * The line number a citation names, whichever of its two shapes it uses:
 * a `#L42` suffix on the href, or a bare `:42` / `path:42` (optionally
 * `:42-55`, first number wins) label in the link text — e.g. `` `foo()` [:395](bar.php) ``
 * or `[bar.php:395](bar.php)`, both used throughout docs/decisions/.
 */
function citedLineNumber(string $target, string $linkText): ?int
{
    if (preg_match('/#L(\d+)$/', trim($target), $m) === 1) {
        return (int) $m[1];
    }

    if (preg_match('/^[\w.\/-]*:(\d+)(?:-\d+)?$/', trim($linkText), $m) === 1) {
        return (int) $m[1];
    }

    return null;
}

/**
 * A bare `:42` / `path:42` label carries no symbol of its own to check drift
 * against — the identifier lives in the prose right before it instead, almost
 * always as the backtick-quoted method name the label is annotating (e.g.
 * `` `AnalysisService::revertToPending()` [:395](…) ``). When the link text is
 * such a label, borrow the nearest backtick span immediately preceding the
 * link on the same line; anything else in between (a plain word, punctuation)
 * means the label isn't annotating that span, so it is left untouched and
 * {@see symbolCandidates} finds nothing, same as a link with no symbol at all.
 */
function resolveSymbolSource(string $line, int $linkStart, string $linkText): string
{
    if (preg_match('/^[\w.\/-]*:\d+(?:-\d+)?$/', trim($linkText)) !== 1) {
        return $linkText;
    }

    $before = substr($line, 0, $linkStart);
    if (preg_match('/`([^`]+)`\s*$/', $before, $m) === 1) {
        return $m[1];
    }

    return $linkText;
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
/** Does this word look like an identifier rather than a word of English prose? */
function isSymbolShaped(string $word): bool
{
    $shapes = [
        '/^[a-z][A-Za-z0-9]*[A-Z]/',        // camelCase
        '/^[a-z0-9]+_[a-z0-9_]+$/',         // snake_case
        '/^[A-Z0-9]+_[A-Z0-9_]+$/',         // SCREAMING_SNAKE
        '/^[A-Z][a-z0-9]+[A-Z][A-Za-z0-9]*$/', // multi-hump PascalCase
    ];

    foreach ($shapes as $shape) {
        if (preg_match($shape, $word) === 1) {
            return true;
        }
    }

    return false;
}

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

        // camelCase, snake_case, a SCREAMING_SNAKE constant, or multi-hump
        // PascalCase. A bare lowercase English word in prose is not a symbol.
        // PascalCase is only a candidate because the `$word === $stem` skip
        // above has already dropped the class-in-its-own-file case, whose line
        // number is meaningless; what is left is a usage site in another file,
        // which can sit anywhere. The second hump keeps capitalised prose
        // ("Inertia", "Every") out.
        if (! isSymbolShaped($word)) {
            continue;
        }

        $candidates[$word] = true;
    }

    return array_keys($candidates);
}
