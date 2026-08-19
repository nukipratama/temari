<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Geo\PolylineProjector;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;
use Imagick;
use ImagickPixel;

/**
 * Renders the share PNG for a run card — today the photo attached to the
 * post-run Telegram notification. Builds a self-contained 9:16 SVG (literal hex
 * colors, no external images) and rasterises it via Imagick + librsvg.
 *
 * Geometry, palette and font roles are a hand-port of the client canvas
 * renderer ({@see resources/js/lib/shareCard.ts}) at its `story` format, so the
 * image a user downloads and the image Telegram shows are the same card. The
 * two run in different runtimes and cannot share code; {@see
 * \Tests\Unit\Services\Run\Story\RunCardImageRendererTest} pins the values that
 * must stay in step.
 *
 * `$layout` mirrors the client's three templates: `rute` (route panel hero +
 * KM + stats + badges), `kartu` (no route panel, the space reallocates to a
 * giant centred KM figure), `stats` (a 2x2 stat grid). `$colorway` mirrors its
 * three palettes. A no-GPS card on `rute` degrades to a route-less panel.
 */
class RunCardImageRenderer
{
    /** Story format, mirroring shareCard.ts `DIMS.story`. */
    private const int W = 1080;

    private const int H = 1920;

    /** Mirrors shareCard.ts `PAD`. */
    private const int PAD = 92;

    private const int CONTENT_W = self::W - (self::PAD * 2);

    /**
     * Mirrors shareCard.ts `CARD_SCALE`. The card takes 90% of each axis,
     * centred, and the rest is mat. Card and canvas share the 9:16 aspect, so
     * one uniform scale is the only inset that doesn't distort — the mat is 5%
     * of each dimension (54 sideways, 96 top and bottom), not an equal pixel
     * border. Everything below is authored in unscaled card coordinates and
     * placed by the transform, so no geometry in this file moves.
     */
    private const float CARD_SCALE = 0.9;

    private const float CARD_OFFSET_X = self::W * (1 - self::CARD_SCALE) / 2;

    private const float CARD_OFFSET_Y = self::H * (1 - self::CARD_SCALE) / 2;

    /** Card body corner radius, mirroring shareCard.ts `CARD_RADIUS`. */
    private const int CARD_RADIUS = 44;

    /**
     * The mat behind the card, mirroring shareCard.ts `CARD_GROUND`:
     * `--color-cream-deep`, the app's own ground. Fixed for every colorway —
     * an exported image has no time of day, so it cannot follow the
     * `--color-surface` drift the running app applies.
     */
    private const string GROUND = '#e2e8ee';

    /**
     * `--shadow-e4` from app.css, the elevation the in-app Kartu mount
     * carries. An SVG `feDropShadow`'s `stdDeviation` is σ and a CSS blur
     * radius is 2σ, so each layer's blur halves and nothing is eyeballed.
     * 23,15,56 is `--color-sky-deep`, the same hue the token casts.
     *
     * One filter per layer, over two caster rects, rather than two primitives
     * in one filter: librsvg resolves a second primitive's omitted `in` to
     * SourceGraphic instead of the previous result, so a stacked filter
     * silently renders the tight layer alone and drops the deep one. Two
     * casters also match how the canvas replays the stack, one fill per layer.
     */
    private const string ELEVATION = <<<'SVG'
        <filter id="elevation-deep" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="24" stdDeviation="28" flood-color="#0b1017" flood-opacity="0.20"/>
        </filter>
        <filter id="elevation-tight" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#0b1017" flood-opacity="0.12"/>
        </filter>
        SVG;

    /**
     * Font families by role, mirroring the `--font-*` tokens in app.css.
     * librsvg resolves these through fontconfig, so the families must be
     * installed in the image (see the font install in the Dockerfile) or text
     * silently falls back to the default sans and the card stops matching.
     */
    private const string FONT_DISPLAY = 'Fraunces';

    private const string FONT_SANS = 'Plus Jakarta Sans';

    private const string FONT_MONO = 'JetBrains Mono';

    /** Route panel geometry, `rute` layout only. */
    private const int PANEL_Y = 560;

    private const int PANEL_H = 580;

    private const int PANEL_PAD = 56;

    /** Thread-band accent: a short stitched strip centred above the footer line. */
    private const float BAND_X = 465.0;

    private const float BAND_Y = 1712.0;

    private const float BAND_W = 150.0;

    private const float BAND_H = 24.0;

    private const float BAND_LEAN = 0.09;

    /**
     * Threadwork colorways, mirroring shareCard.ts `COLORWAYS`. `bg` is the
     * card body only; the mat around it is `GROUND` in every colorway. `name`
     * is always horizon and `rarity` never appears here: both are the fixed
     * brand/collectible bridge, constant across colorways.
     */
    private const array COLORWAYS = [
        'navy' => [
            'bg' => '#0b1017',
            'surfaceSunken' => '#e2e8ee',
            'text' => '#f1f5f8',
            'meta' => 'rgba(245,240,228,0.72)',
            'divider' => 'rgba(245,240,228,0.18)',
            'inkOnSky' => '#9c9ea7',
        ],
        'dawn' => [
            'bg' => '#f1f5f8',
            'surfaceSunken' => '#e2e8ee',
            'text' => '#16181b',
            'meta' => 'rgba(26,24,18,0.72)',
            'divider' => 'rgba(26,24,18,0.18)',
            'inkOnSky' => '#60666d',
        ],
        'ember' => [
            'bg' => '#2a1017',
            'surfaceSunken' => '#e2e8ee',
            'text' => '#f1f5f8',
            'meta' => 'rgba(245,240,228,0.72)',
            'divider' => 'rgba(245,240,228,0.18)',
            'inkOnSky' => '#9c9ea7',
        ],
    ];

    /** The run-name accent, the one hue that never varies by colorway. */
    private const string HORIZON = '#ade047';

    public function __construct(private readonly PolylineProjector $projector)
    {
    }

    /**
     * PNG bytes for the given card. Loads the activity detail if needed, so the
     * caller can pass a bare model.
     */
    public function render(RunCard $card, string $layout = 'rute', string $colorway = 'navy'): string
    {
        $card->loadMissing('activity.detail');

        return $this->rasterise($this->buildSvg($card, $layout, $colorway));
    }

    private function buildSvg(RunCard $card, string $layout = 'rute', string $colorway = 'navy'): string
    {
        $detail = $card->activity->detail ?? null;
        $rarity = $card->rarity->hexColor();
        $pal = self::COLORWAYS[$colorway] ?? self::COLORWAYS['navy'];
        [$bg, $sunken, $text, $meta, $inkOnSky] = [
            $pal['bg'], $pal['surfaceSunken'], $pal['text'], $pal['meta'], $pal['inkOnSky'],
        ];

        $km = $this->formatKm($detail?->distance);
        $badges = array_values($card->badges ?? []);

        $header = $this->header($card, $detail, $rarity, $meta);
        $middle = match ($layout) {
            'kartu' => $this->kartuMiddle($km, $detail, $rarity, $text, $inkOnSky, $badges),
            'stats' => $this->statsMiddle($detail, $km, $text, $inkOnSky, $sunken, $rarity, $badges),
            default => $this->ruteMiddle($km, $detail, $rarity, $text, $inkOnSky, $badges),
        };
        $footer = $this->footer($detail, $rarity, $card->rarity->bandCount(), $inkOnSky, $pal['divider']);

        $w = self::W;
        $h = self::H;
        $sans = self::FONT_SANS;
        $ground = self::GROUND;
        $elevation = self::ELEVATION;
        $radius = self::CARD_RADIUS;

        // The elevation casters: the card's silhouette in canvas coordinates,
        // one per shadow layer. The card group paints over them, so nothing of
        // them survives except the cast falling on the mat.
        $castX = self::CARD_OFFSET_X;
        $castY = self::CARD_OFFSET_Y;
        $castW = $w * self::CARD_SCALE;
        $castH = $h * self::CARD_SCALE;
        $castR = $radius * self::CARD_SCALE;
        $scale = self::CARD_SCALE;

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="{$h}" viewBox="0 0 {$w} {$h}" font-family="{$sans}">
  <defs>
    <filter id="bloom" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="18"/>
    </filter>
{$elevation}
  </defs>
  <rect width="{$w}" height="{$h}" fill="{$ground}"/>
  <rect x="{$castX}" y="{$castY}" width="{$castW}" height="{$castH}" rx="{$castR}" fill="{$bg}" filter="url(#elevation-deep)"/>
  <rect x="{$castX}" y="{$castY}" width="{$castW}" height="{$castH}" rx="{$castR}" fill="{$bg}" filter="url(#elevation-tight)"/>
  <g transform="translate({$castX},{$castY}) scale({$scale})">
  <rect width="{$w}" height="{$h}" rx="{$radius}" fill="{$bg}"/>
  <rect x="6" y="6" width="1068" height="1908" rx="38" fill="none" stroke="{$rarity}" stroke-width="12" opacity="0.55" filter="url(#bloom)"/>
  <rect x="6" y="6" width="1068" height="1908" rx="38" fill="none" stroke="{$rarity}" stroke-width="12"/>

  {$header}

  {$middle}

  {$footer}
  </g>
</svg>
SVG;
    }

    /**
     * Rarity star + word, the run name in display italic, and the meta line —
     * shared by every layout, mirroring the client's common header block.
     */
    private function header(RunCard $card, ?ActivityDetail $detail, string $rarity, string $meta): string
    {
        $rarityLabel = $this->escape(mb_strtoupper($card->rarity->label()));
        $star = $this->star(self::PAD + 15, 196, 19, $rarity);

        $display = self::FONT_DISPLAY;
        $horizon = self::HORIZON;
        $lines = $this->wrap($card->special_move, 19, 2);
        $nameSvg = '';
        $y = 330;
        foreach ($lines as $line) {
            $label = $this->escape($line);
            $nameSvg .= <<<SVG

  <text x="88" y="{$y}" font-family="{$display}" font-style="italic" font-size="92" font-weight="600" fill="{$horizon}">{$label}</text>
SVG;
            $y += 104;
        }

        $metaY = $y + 4;
        $metaLine = $this->escape($this->metaLine($detail));
        $pad = self::PAD;
        $mono = self::FONT_MONO;

        return <<<SVG
{$star}
  <text x="140" y="210" font-family="{$mono}" font-size="34" font-weight="700" letter-spacing="8" fill="{$rarity}">{$rarityLabel}</text>
{$nameSvg}
  <text x="{$pad}" y="{$metaY}" font-size="30" font-weight="500" fill="{$meta}">{$metaLine}</text>
SVG;
    }

    /**
     * `rute` — the route as poster art in a bright pearl window, then the KM
     * figure, stats and badges beneath it.
     *
     * @param  list<string>  $badges
     */
    private function ruteMiddle(
        string $km,
        ?ActivityDetail $detail,
        string $rarity,
        string $text,
        string $inkOnSky,
        array $badges,
    ): string {
        $points = $this->projector->project(
            $detail?->summary_polyline,
            self::CONTENT_W,
            self::PANEL_H,
            self::PANEL_PAD,
        );

        $panel = $this->routePanel($points, $rarity, $inkOnSky);
        $kmSvg = $this->kmHero($km, $rarity, $inkOnSky, 1320, 170, false);
        $stats = $this->statsRow($detail, $text, $inkOnSky, 1470, 1526);
        $badgeSvg = $this->badgeRow($badges, $rarity, $text, 1578);

        return <<<SVG
{$panel}
{$kmSvg}
{$stats}
{$badgeSvg}
SVG;
    }

    /**
     * `kartu` — no route panel, so the reclaimed space goes to a giant centred
     * KM figure, mirroring the client `kartu` template's emphasis.
     *
     * @param  list<string>  $badges
     */
    private function kartuMiddle(
        string $km,
        ?ActivityDetail $detail,
        string $rarity,
        string $text,
        string $inkOnSky,
        array $badges,
    ): string {
        $kmSvg = $this->kmHero($km, $rarity, $inkOnSky, 940, 380, true);
        $stats = $this->statsRow($detail, $text, $inkOnSky, 1300, 1366);
        $badgeSvg = $this->badgeRow($badges, $rarity, $text, 1460);

        return <<<SVG
{$kmSvg}
{$stats}
{$badgeSvg}
SVG;
    }

    /**
     * `stats` — no route panel and no single hero figure; a 2x2 grid of stat
     * tiles instead, with distance as the first cell.
     *
     * @param  list<string>  $badges
     */
    private function statsMiddle(
        ?ActivityDetail $detail,
        string $km,
        string $text,
        string $inkOnSky,
        string $sunken,
        string $rarity,
        array $badges,
    ): string {
        $grid = $this->statsGrid($detail, $km, $text, $inkOnSky, $sunken);
        $badgeSvg = $this->badgeRow($badges, $rarity, $text, 1460);

        return <<<SVG
{$grid}
{$badgeSvg}
SVG;
    }

    /**
     * The KM figure with its KILOMETER caption, left-aligned for `rute` and
     * centred for `kartu`.
     */
    private function kmHero(string $km, string $rarity, string $inkOnSky, int $baseline, int $size, bool $centred): string
    {
        $mono = self::FONT_MONO;
        $labelY = $baseline + 54;
        $x = $centred ? intdiv(self::W, 2) : self::PAD;
        $anchor = $centred ? ' text-anchor="middle"' : '';

        return <<<SVG

  <text x="{$x}" y="{$baseline}" font-family="{$mono}" font-size="{$size}" font-weight="700" fill="{$rarity}"{$anchor}>{$km}</text>
  <text x="{$x}" y="{$labelY}" font-family="{$mono}" font-size="34" font-weight="700" letter-spacing="6" fill="{$inkOnSky}"{$anchor}>KILOMETER</text>
SVG;
    }

    /**
     * Up to three core stats as label-over-value cells, mirroring the in-app
     * card's stat grid. Only cells with data are rendered.
     */
    private function statsRow(?ActivityDetail $detail, string $text, string $inkOnSky, int $yLabel, int $yValue): string
    {
        if ($detail === null) {
            return '';
        }

        $mono = self::FONT_MONO;
        $svg = '';
        $x = self::PAD;
        foreach (array_slice($this->coreStatCells($detail), 0, 3) as [$label, $value]) {
            $label = $this->escape($label);
            $value = $this->escape($value);
            $svg .= <<<SVG

  <text x="{$x}" y="{$yLabel}" font-family="{$mono}" font-size="26" font-weight="700" letter-spacing="3" fill="{$inkOnSky}">{$label}</text>
  <text x="{$x}" y="{$yValue}" font-family="{$mono}" font-size="46" font-weight="700" fill="{$text}">{$value}</text>
SVG;
            $x += 300;
        }

        return $svg;
    }

    /**
     * A 2x2 grid of hero-sized stat tiles, distance first. Only cells with data
     * render, up to 4.
     */
    private function statsGrid(?ActivityDetail $detail, string $km, string $text, string $inkOnSky, string $sunken): string
    {
        /** @var list<array{0:string,1:string}> $cells */
        $cells = [['DISTANCE', "{$km} km"]];
        if ($detail !== null) {
            $cells = [...$cells, ...$this->coreStatCells($detail)];
        }

        [$w, $h] = [430, 380];
        $positions = [[self::PAD, 560], [self::PAD + 466, 560], [self::PAD, 980], [self::PAD + 466, 980]];
        $mono = self::FONT_MONO;
        $svg = '';

        foreach (array_slice($cells, 0, 4) as $i => [$label, $value]) {
            [$x, $y] = $positions[$i];
            $cx = $x + intdiv($w, 2);
            $labelY = $y + 140;
            $valueY = $y + 244;
            $label = $this->escape($label);
            $value = $this->escape($value);
            $svg .= <<<SVG

  <rect x="{$x}" y="{$y}" width="{$w}" height="{$h}" rx="32" fill="{$sunken}" fill-opacity="0.10"/>
  <text x="{$cx}" y="{$labelY}" font-family="{$mono}" font-size="26" font-weight="700" letter-spacing="3" fill="{$inkOnSky}" text-anchor="middle">{$label}</text>
  <text x="{$cx}" y="{$valueY}" font-family="{$mono}" font-size="58" font-weight="700" fill="{$text}" text-anchor="middle">{$value}</text>
SVG;
        }

        return $svg;
    }

    /**
     * Up to three badge chips, rarity-tinted, matching the in-app card's badge
     * row.
     *
     * @param  list<string>  $badges
     */
    private function badgeRow(array $badges, string $rarity, string $text, int $y): string
    {
        if ($badges === []) {
            return '';
        }

        $sans = self::FONT_SANS;
        $svg = '';
        $x = self::PAD;
        $textY = $y + 37;

        foreach (array_slice($badges, 0, 3) as $slug) {
            $name = $this->humanizeBadge($slug);
            $w = 44 + (int) round(mb_strlen($name) * 14.5);
            $textX = $x + 22;
            $label = $this->escape($name);
            $svg .= <<<SVG

  <rect x="{$x}" y="{$y}" width="{$w}" height="58" rx="29" fill="{$rarity}" fill-opacity="0.16" stroke="{$rarity}" stroke-opacity="0.55"/>
  <text x="{$textX}" y="{$textY}" font-family="{$sans}" font-size="26" font-weight="700" fill="{$text}">{$label}</text>
SVG;
            $x += $w + 18;
        }

        return $svg;
    }

    /**
     * Divider, thread-band accent, date stamp and brand wordmark along the
     * card's bottom edge.
     */
    private function footer(?ActivityDetail $detail, string $rarity, int $bandCount, string $inkOnSky, string $divider): string
    {
        $band = $this->threadBandTicks($rarity, $bandCount);
        $date = $this->escape($detail?->start_date_local?->translatedFormat('j M Y') ?? '');
        $pad = self::PAD;
        $right = self::W - self::PAD;
        $mono = self::FONT_MONO;
        $sans = self::FONT_SANS;

        return <<<SVG
<line x1="{$pad}" y1="1672" x2="{$right}" y2="1672" stroke="{$divider}" stroke-width="2"/>
  {$band}
  <text x="{$pad}" y="1830" font-family="{$mono}" font-size="28" font-weight="500" letter-spacing="2" fill="{$inkOnSky}">{$date}</text>
  <text x="{$right}" y="1830" font-family="{$sans}" font-size="30" font-weight="700" letter-spacing="1" fill="{$inkOnSky}" text-anchor="end">temari.app</text>
SVG;
    }

    /**
     * Thread-band accent: a stitched cluster additive to the rarity border, not
     * a re-hue. Up to 3 stitches lean one way; from 4 bands on, a second set
     * leans the other way and crosses them (the interwoven look epic and
     * legendary get). Mirrors the client's `threadBandLines()` in
     * {@see resources/js/lib/runcard.ts}, hand-ported across runtimes.
     */
    private function threadBandTicks(string $color, int $bandCount): string
    {
        $primaryX = match (min($bandCount, 3)) {
            1 => [0.5],
            2 => [0.32, 0.68],
            default => [0.18, 0.5, 0.82],
        };
        $crossX = match (max($bandCount - 3, 0)) {
            1 => [0.36],
            2 => [0.22, 0.6],
            default => [],
        };

        [$x0, $y0, $w, $h, $lean] = [self::BAND_X, self::BAND_Y, self::BAND_W, self::BAND_H, self::BAND_LEAN];

        $lines = [];
        foreach ($primaryX as $nx) {
            $lines[] = $this->threadBandLine($x0 + ($nx - $lean) * $w, $y0 + $h, $x0 + ($nx + $lean) * $w, $y0, $color, 0.95);
        }
        foreach ($crossX as $nx) {
            $lines[] = $this->threadBandLine($x0 + ($nx - $lean) * $w, $y0, $x0 + ($nx + $lean) * $w, $y0 + $h, $color, 0.6);
        }

        return implode("\n  ", $lines);
    }

    private function threadBandLine(float $x1, float $y1, float $x2, float $y2, string $color, float $opacity): string
    {
        return sprintf(
            '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="5" stroke-linecap="round" opacity="%.2f"/>',
            $x1,
            $y1,
            $x2,
            $y2,
            $color,
            $opacity,
        );
    }

    /** A five-point star, the rarity flag's leading glyph. */
    private function star(float $cx, float $cy, float $r, string $color): string
    {
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $radius = $i % 2 === 0 ? $r : $r * 0.42;
            $angle = (M_PI / 5 * $i) - (M_PI / 2);
            $points[] = sprintf('%.1f,%.1f', $cx + cos($angle) * $radius, $cy + sin($angle) * $radius);
        }

        return sprintf('  <polygon points="%s" fill="%s"/>', implode(' ', $points), $color);
    }

    /**
     * Location · weather, omitting any segment with no reading. The date is
     * deliberately absent: the footer already stamps it.
     */
    private function metaLine(?ActivityDetail $detail): string
    {
        return implode('  ·  ', array_filter([
            $this->shortLocation($detail?->location_name),
            $this->weatherLabel($detail),
        ]));
    }

    /**
     * Greedy word wrap to at most `$maxLines` lines of roughly `$perLine`
     * characters. librsvg has no text layout engine, so the break points are
     * approximated from character count rather than measured glyph advances.
     *
     * @return list<string>
     */
    private function wrap(string $value, int $perLine, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($value)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $perLine || $current === '') {
                $current = $candidate;

                continue;
            }
            $lines[] = $current;
            $current = $word;
            if (count($lines) === $maxLines) {
                break;
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        return array_slice($lines, 0, $maxLines);
    }

    private function paceLabel(ActivityDetail $detail): ?string
    {
        $secPerKm = PaceCalculator::secPerKm($detail->distance, $detail->elapsed_time);

        return $secPerKm === null ? null : PaceFormatter::format($secPerKm).'/km';
    }

    /**
     * PACE / HR / DURATION cells in canonical order, shared by `statsRow` and
     * `statsGrid` so the two branches can't drift apart. Only cells with data
     * are included.
     *
     * @return list<array{0:string,1:string}>
     */
    private function coreStatCells(ActivityDetail $detail): array
    {
        $cells = [];
        $pace = $this->paceLabel($detail);
        if ($pace !== null) {
            $cells[] = ['PACE', $pace];
        }
        if ($detail->average_heartrate !== null) {
            $cells[] = ['HR', round($detail->average_heartrate).' bpm'];
        }
        if ($detail->elapsed_time !== null) {
            $cells[] = ['DURATION', DurationFormatter::hms((int) $detail->elapsed_time)];
        }

        return $cells;
    }

    private function humanizeBadge(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }

    /**
     * The first comma-segment of a reverse-geocoded name (e.g. "Gelora Bung
     * Karno" out of "Gelora Bung Karno, Jakarta Pusat, DKI Jakarta, Indonesia"),
     * so the meta line fits the card's width.
     */
    private function shortLocation(?string $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $first = trim(explode(',', $location)[0]);

        return $first === '' ? null : $first;
    }

    /**
     * The route drawn straight onto the card ground as poster art (`rute`
     * only), matching the client canvas, or a placeholder when the card has no
     * drawable GPS track.
     */
    private function routePanel(?string $points, string $rarity, string $inkOnSky): string
    {
        [$x, $y, $w, $h] = [self::PAD, self::PANEL_Y, self::CONTENT_W, self::PANEL_H];

        if ($points === null) {
            $cx = $x + intdiv($w, 2);
            $cy = $y + intdiv($h, 2);
            $sans = self::FONT_SANS;

            return <<<SVG
<rect x="{$x}" y="{$y}" width="{$w}" height="{$h}" rx="40" fill="none" stroke="{$inkOnSky}" stroke-opacity="0.25" stroke-width="2" stroke-dasharray="12 10"/>
  <text x="{$cx}" y="{$cy}" font-family="{$sans}" font-size="34" fill="{$inkOnSky}" text-anchor="middle">Route unavailable</text>
SVG;
        }

        return <<<SVG
<g transform="translate({$x},{$y})">
    <polyline points="{$points}" fill="none" stroke="{$rarity}" stroke-width="9" stroke-linejoin="round" stroke-linecap="round"/>
  </g>
SVG;
    }

    /**
     * Rasterise the SVG string to PNG bytes via Imagick + librsvg. A higher
     * input resolution keeps text and strokes crisp.
     */
    private function rasterise(string $svg): string
    {
        $imagick = new Imagick();

        try {
            $imagick->setBackgroundColor(new ImagickPixel('transparent'));
            $imagick->setResolution(144, 144);
            $imagick->readImageBlob($svg);
            $imagick->setImageFormat('png');

            return $imagick->getImageBlob();
        } finally {
            // Free the MagickWand C resources even if a read/encode throws, so a
            // bad SVG can't leak memory across the long-lived Octane worker.
            $imagick->clear();
        }
    }

    /**
     * "31°C, wind 15 km/h" style label, omitting gracefully when temp/wind are
     * absent. Wind only appears alongside a temperature reading.
     */
    private function weatherLabel(?ActivityDetail $detail): ?string
    {
        if ($detail?->weather_temp_c === null) {
            return null;
        }

        $label = "{$detail->weather_temp_c}°C";

        if ($detail->weather_wind_speed_kmh !== null) {
            $label .= ", wind {$detail->weather_wind_speed_kmh} km/h";
        }

        return $label;
    }

    private function formatKm(?float $distanceMeters): string
    {
        if ($distanceMeters === null) {
            return '0';
        }

        return DecimalFormatter::trimmed(DistanceFormatter::km($distanceMeters));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
