#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Doc citation guard.
 *
 * Fails if any code citation in docs/ points at a path that no longer exists —
 * the most dangerous form of doc rot ("the doc references code that's gone") —
 * or, in a living note, if it uses a `#L42` line anchor or names a symbol the
 * cited file does not declare. Living notes cite code by path plus a named
 * symbol; ADRs are point-in-time records and skip both of those checks.
 *
 * Checks, per docs/**.md (excluding .obsidian/ and underscore-prefixed files like _template.md):
 *   - frontmatter `code_refs:` list items
 *   - inline markdown link targets, e.g. [text](app/Services/Foo.php)
 *   - in living notes: no `#L42` anchors, and a symbol-shaped link text must be declared in the target
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

/** @var list<string> $missing */
$missing = [];

/** @var list<string> $unresolved */
$unresolved = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($docsDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'md') {
        continue;
    }

    $path = $file->getPathname();
    $isAdr = str_contains($path, '/docs/decisions/');

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
                $linkText = $match[1];
                $target = $match[2];
                checkCitation($root, $path, $lineNo, $target, $missing);

                if (! $isAdr) {
                    checkLivingCitation($root, $path, $lineNo, $target, $linkText, $unresolved);
                }
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

if ($unresolved !== []) {
    fwrite(STDERR, "Doc citation guard: these living-note citations use a line anchor or name a symbol the file does not declare:\n");
    foreach ($unresolved as $entry) {
        fwrite(STDERR, "  {$entry}\n");
    }
    fwrite(STDERR, "\nCite path plus a symbol the file declares, with no #L anchor.\n");
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
 * A living note cites code by path plus a named symbol: a `#L42` anchor is an
 * error, and a symbol-shaped link text must be declared in the cited file.
 * Prose link texts, and the cited file's own name, carry no symbol and pass.
 *
 * @param  list<string>  $unresolved
 */
function checkLivingCitation(string $root, string $doc, int $lineNo, string $target, string $linkText, array &$unresolved): void
{
    $target = trim($target);
    if (preg_match('~^(https?:|mailto:|//|#)~', $target) === 1) {
        return;
    }

    $path = trim((string) preg_replace('/#.*$/', '', (string) (preg_split('/\s+/', $target)[0] ?? '')));
    $docRelative = substr($doc, strlen($root) + 1);

    if (preg_match('/#L\d+/', $target) === 1) {
        $unresolved[] = "{$docRelative}:{$lineNo} -> {$target} (line anchor)";
    }

    if ($path === '') {
        return;
    }

    $file = $root.'/'.ltrim($path, '/');
    if (! is_file($file)) {
        $file = dirname($doc).'/'.$path;
    }
    if (! is_file($file)) {
        return;
    }

    $source = (string) file_get_contents($file);
    foreach (symbolCandidates($linkText, basename($path)) as $symbol) {
        if (! declaresSymbol($source, $symbol)) {
            $unresolved[] = "{$docRelative}:{$lineNo} -> {$path} (does not declare {$symbol})";
        }
    }
}

function declaresSymbol(string $source, string $symbol): bool
{
    $name = preg_quote($symbol, '/');
    $patterns = [
        '/\b(?:function|class|interface|trait|enum|const|case|let|var|type)\s+(?:&\s*)?'.$name.'\b/',
        '/\bconst\s+[\w?|\\\\]+\s+'.$name.'\b/',
        '/(?:public|protected|private|readonly|static|var)\b[^;=(\n]*\$'.$name.'\b/',
        '/\bexport\s*\{[^}]*\b'.$name.'\b/',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source) === 1) {
            return true;
        }
    }

    return false;
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
        if (str_contains($basename, $word)) {
            continue;
        }

        // camelCase, snake_case, a SCREAMING_SNAKE constant, or multi-hump
        // PascalCase. A bare lowercase English word in prose is not a symbol,
        // and the second hump keeps capitalised prose ("Inertia", "Every") out.
        if (! isSymbolShaped($word)) {
            continue;
        }

        $candidates[$word] = true;
    }

    return array_keys($candidates);
}
