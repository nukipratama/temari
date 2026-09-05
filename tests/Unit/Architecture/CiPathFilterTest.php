<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The CI workflow skips the heavy jobs when a diff cannot affect them. The
 * failure mode of getting that wrong is the worst kind: tests silently stop
 * running and nothing goes red.
 *
 * The trap this guards is specific. `backend-tests` runs the structure group,
 * which includes DesignTokenMirrorsTest and DesignTokenDocsTest — and those
 * read files that look like frontend or documentation. A filter written from
 * the obvious intuition ("only markdown changed, skip the tests") would stop
 * running the very guard that catches a docs/design-tokens.md regression.
 *
 * So rather than restate the path list, these tests derive it: every entry in
 * MIRROR_FILES, and every doc DesignTokenDocsTest reads, must be classified as
 * backend by the workflow's own regexes. Add a mirror file without widening the
 * filter and this fails.
 */
const CI_WORKFLOW = '.github/workflows/ci.yml';

/** Pull one `NAME='regex'` assignment out of the workflow's classifier step. */
function ciFilterPattern(string $name): string
{
    $yaml = File::get(base_path(CI_WORKFLOW));

    expect(preg_match("/^\s*{$name}='(.+)'$/m", $yaml, $m))->toBe(
        1,
        "The CI workflow no longer defines a {$name} pattern. If the classifier was ".
        'restructured, update this test to match — do not delete it.',
    );

    return $m[1];
}

/** Does the workflow classify `$path` as needing the backend jobs? */
function ciClassifiesAsBackend(string $path): bool
{
    return array_any(
        ['ARCH', 'BACKEND', 'MIRRORS', 'DOCS_READ_BY_TESTS'],
        fn (string $name): bool => preg_match('#'.ciFilterPattern($name).'#', $path) === 1,
    );
}

it('runs the backend jobs for every file the token-mirror test reads', function (): void {
    require_once base_path('tests/Unit/Architecture/DesignTokenMirrorsTest.php');

    $unguarded = collect(MIRROR_FILES)
        ->reject(ciClassifiesAsBackend(...))
        ->values();

    expect($unguarded->all())->toBe(
        [],
        "These files are asserted by DesignTokenMirrorsTest, which runs in backend-tests, but the CI\n".
        "path filter does not classify them as backend — changing one would skip the test that guards it:\n  ".
        $unguarded->implode("\n  "),
    );
})->group('structure');

it('runs the backend jobs for every doc the token-docs test reads', function (): void {
    // DesignTokenDocsTest asserts against these by name; they are markdown, so
    // the intuitive "docs changed, skip the tests" rule would silently skip it.
    $docs = ['CLAUDE.md', 'README.md', 'docs/design-tokens.md', '.claude/skills/temari/SKILL.md'];

    $unguarded = collect($docs)->reject(ciClassifiesAsBackend(...))->values();

    expect($unguarded->all())->toBe(
        [],
        "DesignTokenDocsTest reads these, but the CI path filter treats them as inert documentation:\n  ".
        $unguarded->implode("\n  "),
    );
})->group('structure');

it('runs everything when the workflow itself changes', function (): void {
    expect(ciClassifiesAsBackend(CI_WORKFLOW))->toBeTrue(
        'A change to the CI workflow must not be able to skip the jobs it defines.',
    );
})->group('structure');

it('skips the heavy jobs for planning docs, which nothing asserts against', function (): void {
    // The whole point of the filter. If this starts returning true, the filter
    // has been widened until it no longer saves anything.
    expect(ciClassifiesAsBackend('plan/README.md'))->toBeFalse();
    expect(ciClassifiesAsBackend('docs/decisions/dark-is-the-default-ground.md'))->toBeFalse();
})->group('structure');
