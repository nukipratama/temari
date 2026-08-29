<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Guards the files that keep their own copy of the palette.
 *
 * A `<canvas>` cannot read `var(--color-*)`, and neither can the error pages,
 * which render without the app stylesheet, an inline SVG's fill attributes, a
 * `<meta>` tag in the document head, or PHP that paints an image server-side.
 * So these files hold hex literals and a comment asking the next person to keep
 * them in sync. Nothing imports them, so nothing broke when the ink tier moved
 * — they simply kept painting the old values, including the error layout's
 * gold-on-cream-deep pair at 4.28:1.
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
    '#2a1017' => 'shareCard/RunCardImageRenderer emberDark — the ember hue carried to canvas-background darkness',
    '#fcf9f3' => 'shareCard paper highlight, lighter than any surface token',
    // TemariProto halo strokes. The halo palette reuses the exact token value
    // where one fits (easy is --color-leaf, chill is --color-mood-chill, stone
    // is --color-stone); these are the muted stroke variants with no token
    // equivalent.
    '#8a8474' => 'TemariProto halo neutral',
    '#a87e1a' => 'TemariProto halo blazing',
    '#6f8f2d' => 'TemariProto halo gold',
    // TemariProto medal metals. Gold alone has a token (--color-horizon).
    '#a98f6b' => 'TemariProto medal bronze',
    '#b9c0c9' => 'TemariProto medal silver',
    '#d8f0ff' => 'TemariProto medal platinum',
    '#fffaf0' => 'TemariProto eye highlight, lighter than any surface token',
    '#3b2f1f' => 'TemariProto drop-shadow floodColor',
    // TemariProto HALO_DARK/AURA_ITEMS_DARK/SEASON_COLORS_DARK (F5) — the
    // halo/aura/season rings draw against the page ground, not the mascot's
    // own cream body, so each needs its own dark-legible value derived via
    // inkOnDark() (resources/brand/build-tokens.mjs), same rationale as the
    // light halo strokes above. Several dark values reuse a light token
    // unmodified (already legible both directions) and are not repeated here.
    '#cdc4ac' => 'TemariProto halo neutral, dark ground',
    '#7b71a8' => 'TemariProto halo chill / aura calm, dark ground',
    '#75787c' => 'TemariProto halo stone, dark ground',
    '#bd5769' => 'TemariProto halo wobbly, dark ground',
    '#a46772' => 'TemariProto halo gassed, dark ground',
    '#ab636f' => 'TemariProto aura heatwave / season ember-deep, dark ground',
    '#727881' => 'TemariProto season sky-2, dark ground',
    '#448466' => 'TemariProto season leaf-deep, dark ground',
    '#8a68b4' => 'TemariProto season overloaded, dark ground',
];

const MIRROR_FILES = [
    'resources/js/lib/chartTokens.ts',
    'resources/js/lib/shareCard.ts',
    'resources/js/lib/runcard.ts',
    'resources/js/components/temari/TemariProto.tsx',
    'resources/views/app.blade.php',
    'resources/views/errors/layout.blade.php',
    'app/Services/Run/Story/RunCardImageRenderer.php',
    'app/Enums/Rarity.php',
];

/**
 * Every solid hex a `--color-*` custom property declares anywhere in
 * app.css — not just inside `@theme static` (the light ground). F2 added a
 * second declaration site, `[data-theme='dark'] { ... }`, for the tokens
 * that flip; scoping this to `@theme static` alone would make any dark-only
 * mirror value (e.g. a chart series that needs to stay legible against the
 * dark ground) look stale here even though app.css genuinely declares it.
 *
 * @return list<string>
 */
function declaredTokenValues(): array
{
    $css = File::get(base_path('resources/css/app.css'));
    preg_match_all('/--color-[a-z0-9-]+:\s*(#[0-9a-f]{6});/', $css, $found);

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
