<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Exercises scripts/check-doc-citations.php against a throwaway repo laid out
 * the way the script expects: scripts/, docs/ and the cited source files.
 *
 * @param  array<string, string>  $files  repo-relative path => contents
 * @return array{0: int, 1: string}
 */
function runCitationGuard(array $files): array
{
    $root = sys_get_temp_dir().'/citation-guard-'.uniqid();
    File::ensureDirectoryExists($root.'/scripts');
    File::copy(base_path('scripts/check-doc-citations.php'), $root.'/scripts/check-doc-citations.php');

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($root.'/'.$path));
        File::put($root.'/'.$path, $contents);
    }

    try {
        $process = new Process([PHP_BINARY, $root.'/scripts/check-doc-citations.php']);
        $process->run();

        return [$process->getExitCode(), $process->getErrorOutput().$process->getOutput()];
    } finally {
        File::deleteDirectory($root);
    }
}

const CITATION_GUARD_SOURCE = <<<'PHP'
<?php

class Foo
{
    public int $retryCount = 0;






























    public function doThing(): void
    {
    }
}
PHP;

it('fails a line anchor in a living note', function (): void {
    [$code, $output] = runCitationGuard([
        'app/Foo.php' => CITATION_GUARD_SOURCE,
        'docs/features/foo.md' => "# Foo\n\nSee [the Foo class](../../app/Foo.php#L5).\n",
    ]);

    expect($code)->toBe(1)
        ->and($output)->toContain('docs/features/foo.md:3')
        ->and($output)->toContain('#L5');
});

it('fails a symbol named in the link text that the file does not declare', function (): void {
    [$code, $output] = runCitationGuard([
        'app/Foo.php' => CITATION_GUARD_SOURCE,
        'docs/features/foo.md' => "# Foo\n\nSee [Foo::missingMethod](../../app/Foo.php).\n",
    ]);

    expect($code)->toBe(1)
        ->and($output)->toContain('docs/features/foo.md:3')
        ->and($output)->toContain('missingMethod');
});

it('passes a symbol the cited file declares', function (): void {
    [$code, $output] = runCitationGuard([
        'app/Foo.php' => CITATION_GUARD_SOURCE,
        'docs/features/foo.md' => "# Foo\n\nSee [Foo::doThing](../../app/Foo.php) and [retryCount](../../app/Foo.php).\n",
    ]);

    expect($code)->toBe(0, $output);
});

it('passes a prose link text', function (): void {
    [$code, $output] = runCitationGuard([
        'app/Foo.php' => CITATION_GUARD_SOURCE,
        'docs/features/foo.md' => "# Foo\n\nSee [the Foo class](../../app/Foo.php).\n",
    ]);

    expect($code)->toBe(0, $output);
});

it('passes a line anchor in an ADR, even when the symbol has moved', function (): void {
    [$code, $output] = runCitationGuard([
        'app/Foo.php' => CITATION_GUARD_SOURCE,
        'docs/decisions/foo.md' => "# Foo\n\nSee [Foo::doThing](../../app/Foo.php#L1).\n",
    ]);

    expect($code)->toBe(0, $output);
});

it('still fails a citation whose path does not exist', function (): void {
    [$code, $output] = runCitationGuard([
        'docs/decisions/foo.md' => "# Foo\n\nSee [the Foo class](../../app/Gone.php).\n",
    ]);

    expect($code)->toBe(1)
        ->and($output)->toContain('app/Gone.php');
});
