#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Doc citation guard.
 *
 * Fails if any code citation in docs/ points at a path that no longer exists —
 * the most dangerous form of doc rot ("the doc references code that's gone").
 *
 * Checks, per docs/**.md (excluding .obsidian/ and underscore-prefixed files like _template.md):
 *   - frontmatter `code_refs:` list items
 *   - inline markdown link targets, e.g. [text](app/Services/Foo.php#L42)
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

        if (preg_match_all('/\]\(([^)]+)\)/', $line, $all) > 0) {
            foreach ($all[1] as $target) {
                checkCitation($root, $path, $lineNo, $target, $missing);
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
