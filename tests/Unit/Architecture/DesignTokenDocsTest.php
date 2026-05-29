<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Keeps the design-system docs honest. The Daybreak palette / type scale drifted
 * badly once (CLAUDE.md + README described a removed emerald/terracotta system);
 * these guards fail CI the moment the docs and `app.css @theme` diverge again.
 * In the `structure` group so they run in the fast pre-coverage gate.
 */
it('documents every color/text token family from app.css', function (): void {
    $css = File::get(resource_path('css/app.css'));
    $doc = File::get(base_path('docs/design-tokens.md'));

    // First segment after `--color-` / `--text-` is the token family
    // (e.g. --color-mood-nyala -> "mood", --text-display-2xl -> "display").
    preg_match_all('/--(?:color|text)-([a-z0-9]+)/', $css, $matches);
    $families = collect($matches[1])->unique()->sort()->values();

    $undocumented = $families
        ->reject(fn (string $family): bool => str_contains($doc, $family))
        ->values();

    expect($undocumented->all())->toBe(
        [],
        "These token families exist in app.css @theme but aren't documented in docs/design-tokens.md:\n  ".$undocumented->implode("\n  "),
    );
})->group('structure');

it('keeps the design docs free of removed token names', function (): void {
    // Names that were deleted from the codebase and must not reappear in docs.
    $forbidden = ['text-ink-soft', 'text-ink-meta', 'GradientNumber', '--gradient-subuh'];

    foreach (['CLAUDE.md', 'README.md', 'docs/design-tokens.md'] as $relativePath) {
        $content = File::get(base_path($relativePath));
        foreach ($forbidden as $needle) {
            expect(str_contains($content, $needle))->toBeFalse(
                "{$relativePath} references the removed token name '{$needle}'.",
            );
        }
    }
})->group('structure');
