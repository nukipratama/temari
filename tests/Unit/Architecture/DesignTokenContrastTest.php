<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Guards the derived `-ink` tokens against the ground they actually land on.
 *
 * dawn-shift re-declares `--color-surface` per `body[data-time-of-day]`, so paper
 * is five colours, not one. Deriving each `-ink` against the default alone put
 * eight of them at ~4.3:1 after dark while every audit reported a pass. This
 * reads the shipped stylesheet, so it holds whatever produced the values.
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
 * @return array{tokens: array<string, string>, grounds: array<string, string>}
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
        $shifts,
        PREG_SET_ORDER,
    );

    $grounds = ['day' => $tokens['surface']];
    foreach ($shifts as [, $name, $value]) {
        $grounds[$name] = $value;
    }

    return ['tokens' => $tokens, 'grounds' => $grounds];
}

it('finds every ground dawn-shift can render', function (): void {
    ['grounds' => $grounds] = designTokens();

    // Mirrors the buckets in resources/js/hooks/useDawnShift.ts:6.
    expect(array_keys($grounds))->toBe(['day', 'dawn', 'morning', 'dusk', 'night']);
})->group('structure');

it('keeps every -ink token above AA on every ground', function (): void {
    ['tokens' => $tokens, 'grounds' => $grounds] = designTokens();

    $inks = array_filter(
        $tokens,
        fn (string $name): bool => str_ends_with($name, '-ink') && $name !== 'ink',
        ARRAY_FILTER_USE_KEY,
    );

    expect($inks)->not->toBeEmpty();

    $under = [];
    foreach ($inks as $name => $hex) {
        foreach ($grounds as $ground => $paper) {
            $ratio = tokenContrast($hex, $paper);
            if ($ratio < 4.5) {
                $under[] = sprintf('--color-%s on %s: %.2f', $name, $ground, $ratio);
            }
        }
    }

    expect($under)->toBe([], "These -ink tokens are under 4.5:1 as text:\n  ".implode("\n  ", $under));
})->group('structure');

it('keeps the separator above its floor on every ground', function (): void {
    ['tokens' => $tokens, 'grounds' => $grounds] = designTokens();

    foreach ($grounds as $ground => $paper) {
        expect(tokenContrast($tokens['line'], $paper))
            ->toBeGreaterThanOrEqual(1.4, "--color-line is under 1.4:1 on {$ground}.");
    }
})->group('structure');
