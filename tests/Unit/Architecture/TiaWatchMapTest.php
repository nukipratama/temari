<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * TIA decides which tests a change affects from pcov edges: the lines a test
 * actually executed. A test that reads the filesystem instead — File::allFiles,
 * glob, scandir — executes none of the code it is asserting about, so it records
 * no edge to it and TIA silently stops running it. A test that never runs looks
 * exactly like a test that passes.
 *
 * Pest offers no "always run" marker; the watch map in tests/Pest.php is the only
 * lever, and it is hand-maintained. This is the guard on it. It rotted once
 * already: NarratorsCoverageTest globs the narrator and tool directories, and a
 * brand-new narrator with a prompt naming a tool it does not carry passed locally
 * under TIA while failing under --no-tia.
 *
 * Like CiPathFilterTest, this derives the map from the file rather than restating
 * it, so the two cannot drift.
 */
const PEST_CONFIG = 'tests/Pest.php';

/** Calls that read the filesystem directly and so record no coverage edge. */
const SCANNING_CALLS = '/File::allFiles|File::json|File::directories|\bglob\(|\bscandir\(|new Finder|Finder::create/';

/**
 * The watch map's targets, each a test directory or an exact test file.
 *
 * @return list<string>
 */
function tiaWatchTargets(): array
{
    $source = File::get(base_path(PEST_CONFIG));

    expect(preg_match('/->watch\(\[(.*?)\]\);/s', $source, $block))->toBe(
        1,
        'The TIA watch map is no longer a ->watch([...]) call in '.PEST_CONFIG.
        '. If it moved, update this test to find it — do not delete it.',
    );

    preg_match_all("/=>\s*'([^']+)'/", $block[1], $targets);

    return $targets[1];
}

/** Every test file that scans the filesystem rather than executing the code it asserts on. */
function scanningTestFiles(): array
{
    return collect(File::allFiles(base_path('tests')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->filter(fn ($file): bool => preg_match(SCANNING_CALLS, (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn ($file): string => str_replace(base_path().'/', '', $file->getPathname()))
        // Pest.php is the config that declares the map, not a test it can target.
        ->reject(fn (string $path): bool => $path === PEST_CONFIG)
        ->values()
        ->all();
}

it('routes every filesystem-scanning test through the TIA watch map', function (): void {
    $targets = tiaWatchTargets();

    $unwatched = collect(scanningTestFiles())
        ->reject(fn (string $path): bool => collect($targets)->contains(
            fn (string $target): bool => $path === $target || str_starts_with($path, rtrim($target, '/').'/'),
        ))
        ->values();

    expect($unwatched->all())->toBe(
        [],
        "These tests read the filesystem, so pcov records no edge to the code they assert on and TIA\n".
        "will not re-run them. Add a glob for what each one scans to the watch map in ".PEST_CONFIG.":\n  ".
        $unwatched->implode("\n  "),
    );
})->group('structure');

it('points every watch target at something that exists', function (): void {
    $missing = collect(tiaWatchTargets())
        ->reject(fn (string $target): bool => File::exists(base_path($target)))
        ->values();

    expect($missing->all())->toBe(
        [],
        "The watch map targets these, but nothing is there any more — a renamed or deleted test\n".
        "leaves a glob that silently invalidates nothing:\n  ".$missing->implode("\n  "),
    );
})->group('structure');
