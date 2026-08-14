<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Guards the derived `-ink` tokens against every ground they actually land on.
 *
 * The method S2.9 established was right — target the darkest renderable ground.
 * Its ground list was not: it enumerated the five `--color-surface` values
 * dawn-shift drifts between and missed `--color-cream-deep`, which is what
 * AppShell paints under the whole app and is darker than all five. So nothing
 * here writes grounds down. The values come out of the shipped stylesheet, the
 * backgrounds in play come out of the components, and resources/brand/grounds.json
 * only records what *kind* each one is. A background in use that grounds.json
 * does not classify fails this suite rather than being skipped.
 */
function tokenLuminance(string $hex): float
{
    $n = (int) hexdec(mb_substr($hex, 1));
    $channels = [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];

    $linear = array_map(static function (int $value): float {
        $c = $value / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, $channels);

    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

function tokenContrast(string $a, string $b): float
{
    $pair = [tokenLuminance($a), tokenLuminance($b)];
    sort($pair);

    return ($pair[1] + 0.05) / ($pair[0] + 0.05);
}

/**
 * @return array{tokens: array<string, string>, shifts: array<string, string>}
 */
function designTokens(): array
{
    $css = File::get(resource_path('css/app.css'));

    preg_match('/@theme static \{.*?\n\}/s', $css, $theme);
    preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-f]{6});/', $theme[0], $found, PREG_SET_ORDER);

    $tokens = [];
    foreach ($found as [, $name, $value]) {
        $tokens[$name] = $value;
    }

    preg_match_all(
        '/body\[data-time-of-day=\'([a-z]+)\'\]\s*\{\s*--color-surface:\s*(#[0-9a-f]{6});/',
        $css,
        $matches,
        PREG_SET_ORDER,
    );

    $shifts = [];
    foreach ($matches as [, $name, $value]) {
        $shifts[$name] = $value;
    }

    return ['tokens' => $tokens, 'shifts' => $shifts];
}

/**
 * @return array{paper: list<string>, scoped: list<string>, fill: list<string>, keyword: list<string>}
 */
function groundKinds(): array
{
    return File::json(resource_path('brand/grounds.json'));
}

/**
 * Every `bg-*` utility the components paint, with any `/alpha` modifier dropped.
 * Comments are stripped first: prose like `bg-mood-{key}` is not a class the
 * browser ever sees, and scanning it would invent backgrounds that do not exist.
 *
 * @return list<string>
 */
function paintedBackgrounds(): array
{
    $names = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $source = preg_replace(
            ['#/\*.*?\*/#s', '#//[^\n]*#'],
            ' ',
            $file->getContents(),
        ) ?? '';

        preg_match_all('/\bbg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\b/', $source, $found);
        $names = [...$names, ...$found[1]];
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * The papers any `-ink` can land on: every background grounds.json calls paper,
 * plus each surface dawn-shift drifts to.
 *
 * @param  array<string, string>  $tokens
 * @param  array<string, string>  $shifts
 * @return array<string, string>
 */
function paperGrounds(array $tokens, array $shifts): array
{
    $grounds = [];
    foreach (groundKinds()['paper'] as $name) {
        $grounds[$name] = $tokens[$name];
    }
    foreach ($shifts as $bucket => $value) {
        $grounds["surface · {$bucket}"] = $value;
    }

    return $grounds;
}

it('classifies every background the components paint', function (): void {
    $kinds = groundKinds();
    $known = array_merge($kinds['paper'], $kinds['scoped'], $kinds['fill'], $kinds['keyword']);

    $unclassified = array_values(array_diff(paintedBackgrounds(), $known));

    expect($unclassified)->toBe([], sprintf(
        "These bg-* utilities are painted but unclassified, so nothing scored them:\n  %s\n".
        'Add each to resources/brand/grounds.json as paper (ink lands on it), scoped '.
        "(only its own family's ink does), fill (no ink text) or keyword (not a --color-* token).",
        implode("\n  ", $unclassified),
    ));
})->group('structure');

it('backs every classified colour ground with a declared token', function (): void {
    ['tokens' => $tokens] = designTokens();
    $kinds = groundKinds();

    $missing = [];
    foreach ([...$kinds['paper'], ...$kinds['scoped']] as $name) {
        if (! isset($tokens[$name])) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], sprintf(
        'grounds.json classifies these as a ground, but no --color-* token declares them: %s',
        implode(', ', $missing),
    ));
})->group('structure');

it('finds more grounds than dawn-shift alone declares', function (): void {
    ['tokens' => $tokens, 'shifts' => $shifts] = designTokens();

    // The S2.9 blind spot was scoring only --color-surface and its drifts. A
    // paper ground outside that set is exactly what went unscored, so the
    // derivation is only doing its job while it reaches past them.
    $beyondDawnShift = array_diff_key(
        paperGrounds($tokens, $shifts),
        array_flip(array_map(fn (string $b): string => "surface · {$b}", array_keys($shifts))),
        ['surface' => ''],
    );

    expect($beyondDawnShift)->not->toBeEmpty();
})->group('structure');

it('keeps every -ink token above AA on every ground it lands on', function (): void {
    ['tokens' => $tokens, 'shifts' => $shifts] = designTokens();
    $papers = paperGrounds($tokens, $shifts);
    $scoped = groundKinds()['scoped'];

    $inks = array_filter(
        $tokens,
        fn (string $name): bool => str_ends_with($name, '-ink') && $name !== 'ink',
        ARRAY_FILTER_USE_KEY,
    );

    expect($inks)->not->toBeEmpty();

    $under = [];
    foreach ($inks as $name => $hex) {
        $grounds = $papers;

        // A family's own tinted cell is a ground only that family's ink lands
        // on. The pairing is the naming convention, so a new -bg cell is scored
        // the moment grounds.json calls it scoped.
        $own = mb_substr($name, 0, -mb_strlen('-ink')).'-bg';
        if (in_array($own, $scoped, true) && isset($tokens[$own])) {
            $grounds[$own] = $tokens[$own];
        }

        foreach ($grounds as $ground => $paper) {
            $ratio = tokenContrast($hex, $paper);
            if ($ratio < 4.5) {
                $under[] = sprintf('--color-%s on %s (%s): %.2f', $name, $ground, $paper, $ratio);
            }
        }
    }

    expect($under)->toBe([], "These -ink tokens are under 4.5:1 as text:\n  ".implode("\n  ", $under));
})->group('structure');

it('keeps the separator above its floor on every ground', function (): void {
    ['tokens' => $tokens, 'shifts' => $shifts] = designTokens();

    foreach (paperGrounds($tokens, $shifts) as $ground => $paper) {
        expect(tokenContrast($tokens['line'], $paper))
            ->toBeGreaterThanOrEqual(1.4, "--color-line is under 1.4:1 on {$ground}.");
    }
})->group('structure');
