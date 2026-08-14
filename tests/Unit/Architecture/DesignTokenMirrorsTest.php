<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Guards the files that keep their own copy of the palette.
 *
 * A `<canvas>` cannot read `var(--color-*)`, and neither can the error pages,
 * which render without the app stylesheet. So three files hold hex literals and
 * a comment asking the next person to keep them in sync. Nothing imports them,
 * so nothing broke when the ink tier moved — they simply kept painting the old
 * values, including the error layout's gold-on-cream-deep pair at 4.28:1.
 *
 * Every hex in these files must therefore still be a value `app.css` declares.
 * A token that moves without its mirrors goes red here; a genuinely non-token
 * colour has to be named in OFF_TOKEN, which is the part that fails closed.
 */

/** @var array<string, string> Colours that are deliberately not tokens. */
const OFF_TOKEN = [
    '#35c6da' => 'chartTokens hrZone Z1 — the cool end of the HR ramp, no token equivalent',
    '#2f956a' => 'chartTokens hrZone Z2',
    '#d99a1a' => 'chartTokens hrZone Z3',
    '#c46f1c' => 'chartTokens hrZone Z4',
    '#b8302f' => 'chartTokens hrZone Z5',
    '#2a1017' => 'shareCard emberDark — the ember hue carried to canvas-background darkness',
    '#fcf9f3' => 'shareCard paper highlight, lighter than any surface token',
];

const MIRROR_FILES = [
    'resources/js/lib/chartTokens.ts',
    'resources/js/lib/shareCard.ts',
    'resources/views/errors/layout.blade.php',
];

/** @return list<string> */
function declaredTokenValues(): array
{
    $css = File::get(base_path('resources/css/app.css'));
    preg_match('/@theme static \{.*?\n\}/s', $css, $theme);
    preg_match_all('/--color-[a-z0-9-]+:\s*(#[0-9a-f]{6});/', $theme[0], $found);

    return array_values(array_unique($found[1]));
}

it('keeps every hand-copied palette hex on a value app.css still declares', function (): void {
    $declared = declaredTokenValues();
    $stale = [];

    foreach (MIRROR_FILES as $path) {
        preg_match_all('/#[0-9a-fA-F]{6}\b/', File::get(base_path($path)), $found);

        foreach (array_unique($found[0]) as $hex) {
            $hex = mb_strtolower($hex);
            if (in_array($hex, $declared, true) || array_key_exists($hex, OFF_TOKEN)) {
                continue;
            }
            $stale[] = "{$path}: {$hex}";
        }
    }

    expect($stale)->toBe([], sprintf(
        "These hex literals mirror a token that no longer holds that value:\n  %s\n".
        'Re-copy it from the @theme block in resources/css/app.css, or, if it is genuinely '.
        'not a token, name it in OFF_TOKEN with the reason.',
        implode("\n  ", $stale),
    ));
})->group('structure');

it('keeps the off-token allowlist honest', function (): void {
    // An entry that becomes a real token, or stops being used, is dead weight
    // that would hide the next stale copy behind it.
    $declared = declaredTokenValues();
    $used = mb_strtolower(implode("\n", array_map(
        fn (string $path): string => File::get(base_path($path)),
        MIRROR_FILES,
    )));

    $dead = [];
    foreach (array_keys(OFF_TOKEN) as $hex) {
        if (in_array($hex, $declared, true)) {
            $dead[] = "{$hex} is now a real token — drop it from OFF_TOKEN";
        } elseif (! str_contains($used, $hex)) {
            $dead[] = "{$hex} is no longer used — drop it from OFF_TOKEN";
        }
    }

    expect($dead)->toBe([], implode("\n  ", $dead));
})->group('structure');
