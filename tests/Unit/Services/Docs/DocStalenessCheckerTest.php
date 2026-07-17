<?php

declare(strict_types=1);

use App\Services\Docs\DocStalenessChecker;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/docstale-'.uniqid();
    mkdir($this->dir.'/sub', 0o777, true);
});

afterEach(function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($this->dir);
});

/**
 * @param  list<string>  $codeRefs
 */
function staleFixtureNote(string $path, string $reviewed, array $codeRefs): void
{
    $lines = ['---', 'title: Test', "reviewed: {$reviewed}", 'code_refs:'];

    foreach ($codeRefs as $ref) {
        $lines[] = "  - {$ref}";
    }

    $lines[] = '---';
    $lines[] = '';
    $lines[] = '# Test';

    file_put_contents($path, implode("\n", $lines)."\n");
}

it('flags a note whose cited code changed after the reviewed date', function (): void {
    staleFixtureNote($this->dir.'/sub/a.md', '2026-01-01', ['app/Foo.php', 'app/Bar.php']);

    $resolver = fn (string $path): ?CarbonImmutable => $path === 'app/Foo.php'
        ? CarbonImmutable::parse('2026-02-01')
        : CarbonImmutable::parse('2025-12-01');

    $stale = new DocStalenessChecker()->findStale($this->dir, $resolver);

    expect($stale)->toHaveCount(1)
        ->and($stale[0]['doc'])->toBe('sub/a.md')
        ->and($stale[0]['staleRefs'])->toHaveCount(1)
        ->and($stale[0]['staleRefs'][0]['path'])->toBe('app/Foo.php')
        ->and($stale[0]['staleRefs'][0]['committedAt']->toDateString())->toBe('2026-02-01');
});

it('does not flag a note reviewed on the same day the code changed', function (): void {
    staleFixtureNote($this->dir.'/a.md', '2026-03-01', ['app/Foo.php']);

    $resolver = fn (string $path): ?CarbonImmutable => CarbonImmutable::parse('2026-03-01T18:00:00+07:00');

    expect(new DocStalenessChecker()->findStale($this->dir, $resolver))->toBe([]);
});

it('skips notes that declare no code_refs', function (): void {
    file_put_contents($this->dir.'/moc.md', "---\ntitle: MOC\nreviewed: 2026-01-01\n---\n\n# MOC\n");

    $resolver = fn (string $path): ?CarbonImmutable => CarbonImmutable::parse('2030-01-01');

    expect(new DocStalenessChecker()->findStale($this->dir, $resolver))->toBe([]);
});

it('skips a note with malformed YAML frontmatter instead of throwing', function (): void {
    file_put_contents(
        $this->dir.'/broken.md',
        "---\ntitle: \"unterminated quote\ncode_refs:\n  - app/Foo.php\n---\n\n# Broken\n",
    );

    $resolver = fn (string $path): ?CarbonImmutable => CarbonImmutable::parse('2030-01-01');

    expect(new DocStalenessChecker()->findStale($this->dir, $resolver))->toBe([]);
});

it('ignores underscore-prefixed scaffolding and .obsidian files', function (): void {
    staleFixtureNote($this->dir.'/_template.md', '2026-01-01', ['app/Foo.php']);
    mkdir($this->dir.'/.obsidian');
    staleFixtureNote($this->dir.'/.obsidian/x.md', '2026-01-01', ['app/Foo.php']);

    $resolver = fn (string $path): ?CarbonImmutable => CarbonImmutable::parse('2030-01-01');

    expect(new DocStalenessChecker()->findStale($this->dir, $resolver))->toBe([]);
});

it('ignores code_refs with no known commit time', function (): void {
    staleFixtureNote($this->dir.'/a.md', '2026-01-01', ['app/Gone.php']);

    $resolver = fn (string $path): ?CarbonImmutable => null;

    expect(new DocStalenessChecker()->findStale($this->dir, $resolver))->toBe([]);
});

it('returns an empty list when the docs directory does not exist', function (): void {
    $resolver = fn (string $path): ?CarbonImmutable => CarbonImmutable::parse('2030-01-01');

    expect(new DocStalenessChecker()->findStale($this->dir.'/missing', $resolver))->toBe([]);
});
