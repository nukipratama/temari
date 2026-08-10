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
 * Renders the share PNG for a run card, used both as the public card page's OG
 * image and as the photo attached to the post-run Telegram notification. Mirrors
 * the in-app collectible: a dark card on a rarity-colored border, with the
 * rarity label, name, distance, core stats (pace/HR/duration), badge chips, and
 * (layout-dependent) the route polyline, so the shared image reads as the same
 * card. Builds a self-contained landscape SVG (no external fonts/images, literal
 * hex colors, generic font-family) and rasterises it via Imagick + librsvg.
 *
 * `$layout` picks which of the three branches — mirroring the frontend
 * `shareCard.ts` templates — fills the space between the meta line and the
 * footer: `rute` (default, the original single template: route panel + mini
 * stat row + badges), `kartu` (no route panel; that space reallocates to a
 * larger hero KM figure), or `stats` (no route panel, no single hero number;
 * a 2x2 stat grid instead). `$colorway` picks the palette (`navy` default,
 * `dawn`, `ember`), mirroring the frontend's three colorways. A no-GPS card on
 * the `rute` layout degrades to a route-less panel and leans on the distance
 * figure instead.
 */
class RunCardImageRenderer
{
    /** Right-hand route panel geometry (origin + size within the 1200x630 canvas), `rute` layout only. */
    private const int PANEL_X = 656;

    private const int PANEL_Y = 150;

    private const int PANEL_W = 484;

    private const int PANEL_H = 330;

    private const int PANEL_PAD = 34;

    // Daybreak colorways (kept literal so the SVG is fully self-contained).
    // `sky` is the card body fill, `skyDeep` the canvas-margin fill outside
    // it, `sky2` the inset-panel fill (route panel / stat grid cells), and
    // `inkOnSky` the muted meta/label tone. `navy` is unchanged from before
    // this class had a colorway parameter — zero visual regression for the
    // existing Telegram post.
    private const array COLORWAYS = [
        'navy' => [
            'cream' => '#f6f1e8',
            'sky' => '#1f2747',
            'skyDeep' => '#161b33',
            'sky2' => '#2c355c',
            'inkOnSky' => '#b8ad97',
        ],
        'dawn' => [
            'cream' => '#1a1812',
            'sky' => '#f6f1e8',
            'skyDeep' => '#eee7d6',
            'sky2' => '#e3dccd',
            'inkOnSky' => '#6e6452',
        ],
        'ember' => [
            'cream' => '#f6f1e8',
            'sky' => '#3a2015',
            'skyDeep' => '#2a160f',
            'sky2' => '#4f2c1c',
            'inkOnSky' => '#b8ad97',
        ],
    ];

    public function __construct(private readonly PolylineProjector $projector)
    {
    }

    /**
     * PNG bytes for the given card. Loads the activity detail if needed, so the
     * caller can pass a bare model. Defaults to the original `rute`/`navy`
     * look: the only caller today (the post-run Telegram notification) has no
     * product surface for a human to pick otherwise, so it keeps calling this
     * with no explicit layout/colorway and must keep getting the same image.
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
        [$cream, $sky, $skyDeep, $sky2, $inkOnSky] = [
            $pal['cream'], $pal['sky'], $pal['skyDeep'], $pal['sky2'], $pal['inkOnSky'],
        ];

        $name = $this->escape($card->special_move);
        $rarityLabel = $this->escape(mb_strtoupper($card->rarity->label()));
        $km = $this->formatKm($detail?->distance);

        $dateLabel = $detail?->start_date_local?->translatedFormat('j M Y');
        $location = $this->shortLocation($detail?->location_name);
        $weather = $this->weatherLabel($detail);
        $metaLine = $this->escape(implode('  ·  ', array_filter([$dateLabel, $location, $weather])));

        $badges = array_values($card->badges ?? []);
        $middle = match ($layout) {
            'kartu' => $this->kartuMiddle($km, $detail, $rarity, $cream, $inkOnSky, $badges),
            'stats' => $this->statsGrid($detail, $km, $cream, $inkOnSky, $sky2),
            default => $this->ruteMiddle($km, $detail, $rarity, $cream, $inkOnSky, $sky2, $badges),
        };

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" font-family="sans-serif">
  <rect width="1200" height="630" fill="{$skyDeep}"/>
  <rect x="40" y="40" width="1120" height="550" rx="36" fill="{$sky}" stroke="{$rarity}" stroke-width="5"/>

  <text x="90" y="118" font-size="26" font-weight="700" letter-spacing="6" fill="{$rarity}">{$rarityLabel}</text>
  <text x="88" y="188" font-size="62" font-weight="700" fill="{$cream}">{$name}</text>
  <text x="90" y="226" font-size="22" fill="{$inkOnSky}">{$metaLine}</text>

  {$middle}

  <text x="1150" y="575" font-size="24" font-weight="700" letter-spacing="1" fill="{$inkOnSky}" text-anchor="end">temari.app</text>
</svg>
SVG;
    }

    /**
     * `rute` branch — today's original single template, unchanged: KM hero,
     * up to three mini stat cells, badges, and the route panel on the right.
     *
     * @param  list<string>  $badges
     */
    private function ruteMiddle(
        string $km,
        ?ActivityDetail $detail,
        string $rarity,
        string $cream,
        string $inkOnSky,
        string $sky2,
        array $badges,
    ): string {
        $routePoints = $this->projector->project(
            $detail?->summary_polyline,
            self::PANEL_W,
            self::PANEL_H,
            self::PANEL_PAD,
        );
        $panel = $this->routePanel($routePoints, $rarity, $sky2, $inkOnSky);
        $stats = $this->statsRow($detail, $cream, $inkOnSky);
        $badgeSvg = $this->badgeRow($badges, $rarity, $cream);

        return <<<SVG
  <text x="88" y="360" font-size="120" font-weight="700" fill="{$rarity}">{$km}</text>
  <text x="90" y="398" font-size="26" font-weight="700" letter-spacing="4" fill="{$inkOnSky}">KILOMETER</text>

  {$stats}
  {$badgeSvg}

  {$panel}
SVG;
    }

    /**
     * `kartu` branch — no route panel; that column's space reallocates to a
     * larger hero KM figure (mirrors the JS `kartu` template's emphasis).
     * The mini stat row and badges keep their original sizing, just shifted
     * down to clear the taller KM figure.
     *
     * @param  list<string>  $badges
     */
    private function kartuMiddle(
        string $km,
        ?ActivityDetail $detail,
        string $rarity,
        string $cream,
        string $inkOnSky,
        array $badges,
    ): string {
        $stats = $this->statsRow($detail, $cream, $inkOnSky, 484, 518);
        $badgeSvg = $this->badgeRow($badges, $rarity, $cream, 542);

        return <<<SVG
  <text x="88" y="390" font-size="170" font-weight="700" fill="{$rarity}">{$km}</text>
  <text x="90" y="428" font-size="26" font-weight="700" letter-spacing="4" fill="{$inkOnSky}">KILOMETER</text>

  {$stats}
  {$badgeSvg}
SVG;
    }

    /**
     * `stats` branch — no route panel, no single hero KM figure; a 2x2 grid
     * of hero-sized stat tiles instead (mirrors the JS `stats` template).
     * Distance is the grid's first cell rather than a giant standalone
     * number. Only cells with data render, up to 4.
     */
    private function statsGrid(?ActivityDetail $detail, string $km, string $cream, string $inkOnSky, string $sky2): string
    {
        /** @var list<array{0:string,1:string}> $cells */
        $cells = [['DISTANCE', "{$km} km"]];
        if ($detail !== null) {
            $pace = $this->paceLabel($detail);
            if ($pace !== null) {
                $cells[] = ['PACE', $pace];
            }
            if ($detail->elapsed_time !== null) {
                $cells[] = ['DURATION', DurationFormatter::hms((int) $detail->elapsed_time)];
            }
            if ($detail->average_heartrate !== null) {
                $cells[] = ['HR', round($detail->average_heartrate).' bpm'];
            }
        }

        $positions = [[90, 270], [612, 270], [90, 427], [612, 427]];
        [$w, $h] = [498, 133];
        $svg = '';
        foreach (array_slice($cells, 0, 4) as $i => [$label, $value]) {
            [$x, $y] = $positions[$i];
            $cx = $x + intdiv($w, 2);
            $labelY = $y + 50;
            $valueY = $y + 95;
            $label = $this->escape($label);
            $value = $this->escape($value);
            $svg .= <<<SVG

  <rect x="{$x}" y="{$y}" width="{$w}" height="{$h}" rx="20" fill="{$sky2}"/>
  <text x="{$cx}" y="{$labelY}" font-size="20" font-weight="700" letter-spacing="3" fill="{$inkOnSky}" text-anchor="middle">{$label}</text>
  <text x="{$cx}" y="{$valueY}" font-size="44" font-weight="700" fill="{$cream}" text-anchor="middle">{$value}</text>
SVG;
        }

        return $svg;
    }

    /**
     * Up to three core stats (pace / HR / duration) as label-over-value cells,
     * mirroring the in-app card's stat grid so the shared image reads as the
     * same collectible. Only cells with data are rendered. `$yLabel`/`$yValue`
     * let the `kartu` branch shift the row down to clear its taller KM hero.
     */
    private function statsRow(?ActivityDetail $detail, string $cream, string $inkOnSky, int $yLabel = 454, int $yValue = 488): string
    {
        if ($detail === null) {
            return '';
        }

        /** @var list<array{0:string,1:string}> $cells */
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

        $svg = '';
        $x = 90;
        foreach (array_slice($cells, 0, 3) as [$label, $value]) {
            $label = $this->escape($label);
            $value = $this->escape($value);
            $svg .= <<<SVG

  <text x="{$x}" y="{$yLabel}" font-size="18" font-weight="700" letter-spacing="2" fill="{$inkOnSky}">{$label}</text>
  <text x="{$x}" y="{$yValue}" font-size="30" font-weight="700" fill="{$cream}">{$value}</text>
SVG;
            $x += 175;
        }

        return $svg;
    }

    /**
     * Up to three badge chips (rarity-tinted), matching the in-app card's badge row.
     * `$y` lets the `kartu` branch shift the row down to clear its taller KM hero.
     *
     * @param  list<string>  $badges
     */
    private function badgeRow(array $badges, string $rarity, string $cream, int $y = 512): string
    {
        if ($badges === []) {
            return '';
        }

        $svg = '';
        $x = 90;
        $textY = $y + 27;
        foreach (array_slice($badges, 0, 3) as $slug) {
            $name = $this->humanizeBadge($slug);
            $w = 32 + (int) round(mb_strlen($name) * 11.5);
            $textX = $x + 16;
            $label = $this->escape($name);
            $svg .= <<<SVG

  <rect x="{$x}" y="{$y}" width="{$w}" height="42" rx="21" fill="{$rarity}" fill-opacity="0.16" stroke="{$rarity}" stroke-opacity="0.55"/>
  <text x="{$textX}" y="{$textY}" font-size="20" font-weight="700" fill="{$cream}">{$label}</text>
SVG;
            $x += $w + 14;
        }

        return $svg;
    }

    private function paceLabel(ActivityDetail $detail): ?string
    {
        $secPerKm = PaceCalculator::secPerKm($detail->distance, $detail->elapsed_time);

        return $secPerKm === null ? null : PaceFormatter::format($secPerKm).'/km';
    }

    private function humanizeBadge(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }

    /**
     * The first comma-segment of a reverse-geocoded name (e.g. "Gelora Bung
     * Karno" out of "Gelora Bung Karno, Jakarta Pusat, DKI Jakarta, Indonesia"),
     * so the meta line fits the card's left column instead of running under the
     * route panel.
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
     * The right-hand hero panel (`rute` layout only): the fitted route
     * polyline, or a "no route" placeholder when the card has no drawable
     * GPS track.
     */
    private function routePanel(?string $points, string $rarity, string $sky2, string $inkOnSky): string
    {
        [$x, $y, $w, $h] = [self::PANEL_X, self::PANEL_Y, self::PANEL_W, self::PANEL_H];

        $frame = <<<SVG
<rect x="{$x}" y="{$y}" width="{$w}" height="{$h}" rx="24" fill="{$sky2}" stroke="{$rarity}" stroke-opacity="0.35" stroke-width="2"/>
SVG;

        if ($points === null) {
            $cx = $x + intdiv($w, 2);
            $cy = $y + intdiv($h, 2);

            return $frame . <<<SVG

  <text x="{$cx}" y="{$cy}" font-size="26" fill="{$inkOnSky}" text-anchor="middle">Route unavailable</text>
SVG;
        }

        return $frame . <<<SVG

  <g transform="translate({$x},{$y})">
    <polyline points="{$points}" fill="none" stroke="{$rarity}" stroke-width="6" stroke-linejoin="round" stroke-linecap="round"/>
  </g>
SVG;
    }

    /**
     * Rasterise the SVG string to PNG bytes via Imagick + librsvg. A higher input
     * resolution keeps text/strokes crisp before Imagick fits the SVG canvas.
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
     * "31°C, wind 15 km/h" style label, omitting gracefully when temp/wind
     * are absent. Wind only appears alongside a temperature reading.
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
