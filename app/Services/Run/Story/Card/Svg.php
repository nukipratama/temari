<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card;

/**
 * The drawing primitives the three card styles share, plus the two bits of
 * geometry they need (a fitted route path and a contour ring).
 *
 * Everything emitted here is plain SVG librsvg can rasterise: fills, strokes,
 * gradients, clip paths and `feDropShadow`. No CSS, no external images.
 *
 * librsvg has no text layout engine and PHP cannot measure a glyph advance, so
 * nothing here positions one string from another's width: every variable-length
 * string a style draws is left-anchored at a known x, right-anchored at the
 * opposite edge, or centred in a box.
 */
final class Svg
{
    /** Vendored italic-only; always paired with `italic: true`. */
    public const string DISPLAY = 'Fraunces';

    public const string SANS = 'Plus Jakarta Sans';

    public const string MONO = 'JetBrains Mono';

    /**
     * JetBrains Mono's advance width, 600 of its 1000 units per em. A fact of
     * the font rather than a measurement, which is what lets {@see monoWidth()}
     * size a box around mono text exactly.
     */
    private const float MONO_ADVANCE = 0.6;

    /**
     * The fixed export palette. A shared image has no ground to follow, so
     * every value here is the literal light-ground token from
     * `resources/css/app.css` rather than a ground-reactive one.
     */
    public const string SKY = '#171f28';

    public const string SKY_DEEP = '#0b1017';

    public const string HORIZON = '#ade047';

    public const string HORIZON_INK = '#546d23';

    public const string CREAM = '#f1f5f8';

    public const string CREAM_DEEP = '#e2e8ee';

    public const string SURFACE_ELEV = '#f8fbfe';

    public const string INK = '#16181b';

    public const string INK_2 = '#34373c';

    public const string INK_3 = '#60666d';

    public const string INK_ON_SKY = '#9c9ea7';

    public const string LINE = '#bfc5cc';

    public const string LINE_STRONG = '#b2b9c2';

    public const string LEAF = '#2f8f63';

    public const string LEAF_INK = '#226748';

    public const string EMBER = '#b23a4f';

    public const string EMBER_INK = '#9b3245';

    public const string CITRUS = '#c9971f';

    /** The broadsheet's warm PR field: sky-deep pulled toward the citrus hue. */
    public const string PR_GROUND = '#140d16';

    public static function doc(int $width, int $height, string $body, string $defs = ''): string
    {
        $defsBlock = $defs === '' ? '' : "<defs>{$defs}</defs>";

        return '<?xml version="1.0" encoding="UTF-8"?>'
            ."<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\" "
            ."viewBox=\"0 0 {$width} {$height}\">{$defsBlock}{$body}</svg>";
    }

    public static function text(
        string $value,
        float $x,
        float $y,
        float $size,
        string $fill,
        string $family = self::MONO,
        int $weight = 400,
        string $anchor = 'start',
        bool $italic = false,
        ?float $tracking = null,
        ?float $opacity = null,
        ?string $transform = null,
    ): string {
        // A label with nothing to say draws nothing. Guarding here rather than
        // at each of the three styles' call sites means no block can ship a
        // caption over an empty value.
        if (trim($value) === '') {
            return '';
        }

        return self::tag('text', [
            'x' => self::num($x),
            'y' => self::num($y),
            'font-family' => $family,
            'font-size' => self::num($size),
            'font-weight' => (string) $weight,
            'font-style' => $italic ? 'italic' : null,
            'text-anchor' => $anchor === 'start' ? null : $anchor,
            'letter-spacing' => $tracking === null ? null : self::num($tracking),
            'fill' => $fill,
            'fill-opacity' => $opacity === null ? null : self::num($opacity),
            'transform' => $transform,
        ], self::escape($value));
    }

    /** The brand wordmark, the one place Fraunces is set. */
    public static function wordmark(float $x, float $y, float $size, string $fill, string $anchor = 'start'): string
    {
        return self::text(
            'temari',
            $x,
            $y,
            $size,
            $fill,
            family: self::DISPLAY,
            weight: 600,
            anchor: $anchor,
            italic: true
        );
    }

    public static function rect(
        float $x,
        float $y,
        float $width,
        float $height,
        string $fill = 'none',
        ?string $stroke = null,
        ?float $strokeWidth = null,
        ?float $radius = null,
        ?float $opacity = null,
        ?float $strokeOpacity = null,
        ?string $dash = null,
    ): string {
        return self::tag('rect', [
            'x' => self::num($x),
            'y' => self::num($y),
            'width' => self::num($width),
            'height' => self::num($height),
            'rx' => $radius === null ? null : self::num($radius),
            'fill' => $fill,
            'stroke' => $stroke,
            'stroke-width' => $strokeWidth === null ? null : self::num($strokeWidth),
            'stroke-dasharray' => $dash,
            'fill-opacity' => $opacity === null ? null : self::num($opacity),
            'stroke-opacity' => $strokeOpacity === null ? null : self::num($strokeOpacity),
        ]);
    }

    public static function line(
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        string $stroke,
        float $width = 1,
        ?float $opacity = null,
        ?string $dash = null,
        ?string $cap = null,
    ): string {
        return self::tag('line', [
            'x1' => self::num($x1),
            'y1' => self::num($y1),
            'x2' => self::num($x2),
            'y2' => self::num($y2),
            'stroke' => $stroke,
            'stroke-width' => self::num($width),
            'stroke-opacity' => $opacity === null ? null : self::num($opacity),
            'stroke-dasharray' => $dash,
            'stroke-linecap' => $cap,
        ]);
    }

    public static function circle(
        float $cx,
        float $cy,
        float $radius,
        string $fill = 'none',
        ?string $stroke = null,
        ?float $strokeWidth = null,
        ?float $opacity = null,
        ?float $strokeOpacity = null,
    ): string {
        return self::tag('circle', [
            'cx' => self::num($cx),
            'cy' => self::num($cy),
            'r' => self::num($radius),
            'fill' => $fill,
            'stroke' => $stroke,
            'stroke-width' => $strokeWidth === null ? null : self::num($strokeWidth),
            'fill-opacity' => $opacity === null ? null : self::num($opacity),
            'stroke-opacity' => $strokeOpacity === null ? null : self::num($strokeOpacity),
        ]);
    }

    public static function path(
        string $d,
        string $fill = 'none',
        ?string $stroke = null,
        ?float $strokeWidth = null,
        ?float $opacity = null,
        ?float $strokeOpacity = null,
        ?string $dash = null,
    ): string {
        return self::tag('path', [
            'd' => $d,
            'fill' => $fill,
            'stroke' => $stroke,
            'stroke-width' => $strokeWidth === null ? null : self::num($strokeWidth),
            'fill-opacity' => $opacity === null ? null : self::num($opacity),
            'stroke-opacity' => $strokeOpacity === null ? null : self::num($strokeOpacity),
            'stroke-dasharray' => $dash,
            'stroke-linecap' => 'round',
            'stroke-linejoin' => 'round',
        ]);
    }

    public static function group(string $inner, ?string $transform = null, ?string $clip = null): string
    {
        return self::tag('g', ['transform' => $transform, 'clip-path' => $clip], $inner);
    }

    /**
     * A route path out of already-projected points.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function polylinePath(array $points, bool $close = false): string
    {
        if ($points === []) {
            return '';
        }

        $d = 'M'.self::num($points[0][0]).','.self::num($points[0][1]);
        foreach (array_slice($points, 1) as [$x, $y]) {
            $d .= 'L'.self::num($x).','.self::num($y);
        }

        return $close ? $d.'Z' : $d;
    }

    /**
     * A closed, gently irregular ring — style C's contour terrain. Three
     * summed harmonics off a seeded sequence, so the same run always draws the
     * same landscape.
     */
    public static function closedLoop(float $cx, float $cy, float $rx, float $ry, int $seed, float $wobble = 0.16): string
    {
        $random = self::seeded($seed + 3);
        $amplitudes = [$wobble, $wobble * 0.6, $wobble * 0.35];
        $harmonics = [2 + (int) ($random() * 3), 4 + (int) ($random() * 3), 7 + (int) ($random() * 3)];
        $phases = [$random() * 6.3, $random() * 6.3, $random() * 6.3];

        $points = [];
        $steps = 46;
        for ($i = 0; $i < $steps; $i++) {
            $theta = ($i / $steps) * M_PI * 2;
            $magnitude = 1.0;
            for ($h = 0; $h < 3; $h++) {
                $magnitude += $amplitudes[$h] * sin($harmonics[$h] * $theta + $phases[$h]);
            }
            $points[] = [$cx + cos($theta) * $rx * $magnitude, $cy + sin($theta) * $ry * $magnitude];
        }

        return self::polylinePath($points, close: true);
    }

    /**
     * Evenly spaced picks along a point list, for km ticks and split dots.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    public static function along(array $points, int $count): array
    {
        if ($points === [] || $count < 1) {
            return [];
        }

        $picked = [];
        for ($i = 1; $i <= $count; $i++) {
            $picked[] = $points[(int) round((count($points) - 1) * ($i / ($count + 1)))];
        }

        return $picked;
    }

    /**
     * Whether a projected trace ends where it started, within a loose fraction
     * of the box diagonal — a loop run rather than an out-and-back.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function isClosedLoop(array $points, float $width, float $height): bool
    {
        if (count($points) < 2) {
            return false;
        }

        $first = $points[0];
        $last = $points[count($points) - 1];

        return hypot($last[0] - $first[0], $last[1] - $first[1]) <= hypot($width, $height) * 0.03;
    }

    /** Dark ink on a light fill, cream on a dark one, by relative luminance. */
    public static function readableInk(string $hex): string
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $value = (int) hexdec(mb_substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return $luminance > 0.42 ? self::INK : self::CREAM;
    }

    /**
     * The exact width a mono string occupies. JetBrains Mono is monospaced, so
     * this is arithmetic on a known advance rather than a guess at a glyph's
     * width — which is why only mono text may be boxed this way.
     */
    public static function monoWidth(string $value, float $size, float $tracking = 0): float
    {
        return mb_strlen($value) * ($size * self::MONO_ADVANCE + $tracking);
    }

    /**
     * Join as many parts as fit the character budget, never splitting one. A
     * legend line would rather drop a badge than print half its name.
     *
     * @param  list<string>  $parts
     */
    public static function joinWithin(array $parts, string $glue, int $characters): string
    {
        $joined = '';
        foreach ($parts as $part) {
            $candidate = $joined === '' ? $part : $joined.$glue.$part;
            if (mb_strlen($candidate) > $characters) {
                break;
            }
            $joined = $candidate;
        }

        return $joined;
    }

    /**
     * A label/value list with the empty-valued entries dropped, so a ruled row
     * never divides its width by a cell it cannot fill.
     *
     * @param  list<array{0: string, 1: string}>  $cells
     * @return list<array{0: string, 1: string}>
     */
    public static function filledCells(array $cells): array
    {
        return array_values(array_filter($cells, fn (array $cell): bool => trim($cell[1]) !== ''));
    }

    /** Hard character budget for a variable-length label, with no ellipsis tail. */
    public static function clip(string $value, int $characters): string
    {
        return mb_strlen($value) <= $characters ? $value : mb_substr($value, 0, $characters);
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Round to two decimals and drop the trailing zeros, to keep the SVG small. */
    public static function num(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }

    /**
     * A deterministic 0..1 sequence — the same linear congruential generator
     * the design round drew its contours with, so the PHP plate matches the
     * mockup for a given seed.
     *
     * @return callable(): float
     */
    private static function seeded(int $seed): callable
    {
        $state = $seed * 9301 + 49297;

        return function () use (&$state): float {
            $state = ($state * 9301 + 49297) % 233280;

            return $state / 233280;
        };
    }

    /**
     * @param  array<string, string|null>  $attributes
     */
    private static function tag(string $name, array $attributes, ?string $inner = null): string
    {
        $pairs = '';
        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $pairs .= ' '.$key.'="'.$value.'"';
        }

        return $inner === null ? "<{$name}{$pairs}/>" : "<{$name}{$pairs}>{$inner}</{$name}>";
    }
}
