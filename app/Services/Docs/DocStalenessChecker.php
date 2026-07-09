<?php

declare(strict_types=1);

namespace App\Services\Docs;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reports docs/ notes whose cited code changed after the note was last reviewed.
 *
 * The citation guard ([scripts/check-doc-citations.php]) proves a note points at
 * code that still exists; this proves a note was looked at since that code last
 * moved. It can't read prose for truth, so a flag means "re-check", not "wrong".
 *
 * Git access is injected as a callable so the logic is testable without a repo.
 */
class DocStalenessChecker
{
    /**
     * @param  callable(string): ?CarbonImmutable  $lastCommittedAt  resolves a repo-relative code path to its last commit time (null when untracked / unknown)
     * @return list<array{doc: string, reviewed: CarbonImmutable, staleRefs: list<array{path: string, committedAt: CarbonImmutable}>}>
     */
    public function findStale(string $docsDir, callable $lastCommittedAt): array
    {
        $findings = [];

        foreach ($this->docFiles($docsDir) as $absolute) {
            $meta = $this->frontmatter($absolute);

            if ($meta === null || $meta['reviewed'] === null || $meta['codeRefs'] === []) {
                continue;
            }

            $staleRefs = [];

            foreach ($meta['codeRefs'] as $ref) {
                $committedAt = $lastCommittedAt($ref);

                if ($committedAt instanceof CarbonImmutable && $committedAt->toDateString() > $meta['reviewed']->toDateString()) {
                    $staleRefs[] = ['path' => $ref, 'committedAt' => $committedAt];
                }
            }

            if ($staleRefs !== []) {
                $findings[] = [
                    'doc' => $this->relative($docsDir, $absolute),
                    'reviewed' => $meta['reviewed'],
                    'staleRefs' => $staleRefs,
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function docFiles(string $docsDir): array
    {
        if (! is_dir($docsDir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docsDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $path = $file->getPathname();

            // Mirror the citation guard: skip the Obsidian vault config and
            // underscore-prefixed scaffolding like _template.md.
            if (str_contains($path, '/.obsidian/') || str_starts_with($file->getBasename(), '_')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * @return array{reviewed: ?CarbonImmutable, codeRefs: list<string>}|null
     */
    private function frontmatter(string $path): ?array
    {
        $contents = (string) file_get_contents($path);

        if (preg_match('/\A---\R(.*?)\R---/s', $contents, $matches) !== 1) {
            return null;
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($parsed)) {
            return null;
        }

        // Symfony Yaml coerces a bare `2026-06-20` to a Unix timestamp (YAML 1.1),
        // so normalize whatever shape the date scalar arrives in.
        $rawReviewed = $parsed['reviewed'] ?? null;
        $reviewed = match (true) {
            $rawReviewed instanceof DateTimeInterface => CarbonImmutable::instance($rawReviewed),
            is_int($rawReviewed) => CarbonImmutable::createFromTimestamp($rawReviewed),
            is_string($rawReviewed) && $rawReviewed !== '' => CarbonImmutable::parse($rawReviewed),
            default => null,
        };

        $codeRefs = [];

        if (isset($parsed['code_refs']) && is_array($parsed['code_refs'])) {
            foreach ($parsed['code_refs'] as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $codeRefs[] = $ref;
                }
            }
        }

        return ['reviewed' => $reviewed, 'codeRefs' => $codeRefs];
    }

    private function relative(string $docsDir, string $absolute): string
    {
        $prefix = rtrim($docsDir, '/').'/';

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }
}
