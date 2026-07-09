<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Geo\PolylineProjector;
use Imagick;
use ImagickPixel;

/**
 * Renders the share PNG for a run card, used both as the public card page's OG
 * image and as the photo attached to the post-run Telegram notification. Mirrors
 * the in-app collectible: a dark card on a rarity-colored border, with the
 * rarity label, name, distance, core stats (pace/HR/duration), badge chips, and
 * the route polyline, so the shared image reads as the same card. Builds a
 * self-contained landscape SVG (no external fonts/images, literal hex colors,
 * generic font-family) and rasterises it via Imagick + librsvg. A no-GPS card
 * degrades to a route-less panel and leans on the distance figure instead.
 */
class RunCardImageRenderer
{
    /** Right-hand route panel geometry (origin + size within the 1200x630 canvas). */
    private const int PANEL_X = 656;

    private const int PANEL_Y = 150;

    private const int PANEL_W = 484;

    private const int PANEL_H = 330;

    private const int PANEL_PAD = 34;

    // Daybreak palette (kept literal so the SVG is fully self-contained).
    private const string CREAM = '#f6f1e8';

    private const string SKY = '#1f2747';

    private const string SKY_DEEP = '#161b33';

    private const string SKY_2 = '#2c355c';

    private const string INK_ON_SKY = '#b8ad97';

    public function __construct(private readonly PolylineProjector $projector)
    {
    }

    /**
     * PNG bytes for the given card. Loads the activity detail if needed, so the
     * caller can pass a bare model.
     */
    public function render(RunCard $card): string
    {
        $card->loadMissing('activity.detail');

        return $this->rasterise($this->buildSvg($card));
    }

    private function buildSvg(RunCard $card): string
    {
        $detail = $card->activity->detail ?? null;
        $rarity = $card->rarity->hexColor();

        $name = $this->escape($card->special_move);
        $rarityLabel = $this->escape(mb_strtoupper($card->rarity->label()));
        $km = $this->formatKm($detail?->distance);

        $dateLabel = $detail?->start_date_local?->translatedFormat('j M Y');
        $location = $this->shortLocation($detail?->location_name);
        $weather = $this->weatherLabel($detail);
        $metaLine = $this->escape(implode('  ·  ', array_filter([$dateLabel, $location, $weather])));

        $routePoints = $this->projector->project(
            $detail?->summary_polyline,
            self::PANEL_W,
            self::PANEL_H,
            self::PANEL_PAD,
        );
        $panel = $this->routePanel($routePoints, $rarity);
        $stats = $this->statsRow($detail);
        $badges = $this->badgeRow(array_values($card->badges ?? []), $rarity);

        [$cream, $sky, $skyDeep, $inkOnSky] = [self::CREAM, self::SKY, self::SKY_DEEP, self::INK_ON_SKY];

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" font-family="sans-serif">
  <rect width="1200" height="630" fill="{$skyDeep}"/>
  <rect x="40" y="40" width="1120" height="550" rx="36" fill="{$sky}" stroke="{$rarity}" stroke-width="5"/>

  <text x="90" y="118" font-size="26" font-weight="700" letter-spacing="6" fill="{$rarity}">{$rarityLabel}</text>
  <text x="88" y="188" font-size="62" font-weight="700" fill="{$cream}">{$name}</text>
  <text x="90" y="226" font-size="22" fill="{$inkOnSky}">{$metaLine}</text>

  <text x="88" y="360" font-size="120" font-weight="700" fill="{$rarity}">{$km}</text>
  <text x="90" y="398" font-size="26" font-weight="700" letter-spacing="4" fill="{$inkOnSky}">KILOMETER</text>

  {$stats}
  {$badges}

  {$panel}

  <text x="1150" y="575" font-size="24" font-weight="700" letter-spacing="1" fill="{$inkOnSky}" text-anchor="end">temanlari.app</text>
</svg>
SVG;
    }

    /**
     * Up to three core stats (pace / HR / duration) as label-over-value cells,
     * mirroring the in-app card's stat grid so the shared image reads as the
     * same collectible. Only cells with data are rendered.
     */
    private function statsRow(?ActivityDetail $detail): string
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
        if ($detail->moving_time !== null) {
            $cells[] = ['DURASI', $this->formatDuration((int) $detail->moving_time)];
        }

        [$cream, $inkOnSky] = [self::CREAM, self::INK_ON_SKY];
        $svg = '';
        $x = 90;
        foreach (array_slice($cells, 0, 3) as [$label, $value]) {
            $label = $this->escape($label);
            $value = $this->escape($value);
            $svg .= <<<SVG

  <text x="{$x}" y="454" font-size="18" font-weight="700" letter-spacing="2" fill="{$inkOnSky}">{$label}</text>
  <text x="{$x}" y="488" font-size="30" font-weight="700" fill="{$cream}">{$value}</text>
SVG;
            $x += 175;
        }

        return $svg;
    }

    /**
     * Up to three badge chips (rarity-tinted), matching the in-app card's badge row.
     *
     * @param  list<string>  $badges
     */
    private function badgeRow(array $badges, string $rarity): string
    {
        if ($badges === []) {
            return '';
        }

        $cream = self::CREAM;
        $svg = '';
        $x = 90;
        foreach (array_slice($badges, 0, 3) as $slug) {
            $name = $this->humanizeBadge($slug);
            $w = 32 + (int) round(mb_strlen($name) * 11.5);
            $textX = $x + 16;
            $label = $this->escape($name);
            $svg .= <<<SVG

  <rect x="{$x}" y="512" width="{$w}" height="42" rx="21" fill="{$rarity}" fill-opacity="0.16" stroke="{$rarity}" stroke-opacity="0.55"/>
  <text x="{$textX}" y="539" font-size="20" font-weight="700" fill="{$cream}">{$label}</text>
SVG;
            $x += $w + 14;
        }

        return $svg;
    }

    private function paceLabel(ActivityDetail $detail): ?string
    {
        if ($detail->distance === null || $detail->distance <= 0 || $detail->moving_time === null) {
            return null;
        }

        $secPerKm = (int) round($detail->moving_time / ($detail->distance / 1000));

        return $this->formatPace($secPerKm).'/km';
    }

    private function formatPace(int $secPerKm): string
    {
        return sprintf('%d:%02d', intdiv($secPerKm, 60), $secPerKm % 60);
    }

    private function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
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
     * The right-hand hero panel: the fitted route polyline, or a "no route"
     * placeholder when the card has no drawable GPS track.
     */
    private function routePanel(?string $points, string $rarity): string
    {
        [$x, $y, $w, $h, $sky2, $inkOnSky] = [
            self::PANEL_X, self::PANEL_Y, self::PANEL_W, self::PANEL_H, self::SKY_2, self::INK_ON_SKY,
        ];

        $frame = <<<SVG
<rect x="{$x}" y="{$y}" width="{$w}" height="{$h}" rx="24" fill="{$sky2}" stroke="{$rarity}" stroke-opacity="0.35" stroke-width="2"/>
SVG;

        if ($points === null) {
            $cx = $x + intdiv($w, 2);
            $cy = $y + intdiv($h, 2);

            return $frame . <<<SVG

  <text x="{$cx}" y="{$cy}" font-size="26" fill="{$inkOnSky}" text-anchor="middle">Rute tidak tersedia</text>
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
     * "31°C, angin 15 km/j" style label, omitting gracefully when temp/wind
     * are absent. Wind only appears alongside a temperature reading.
     */
    private function weatherLabel(?ActivityDetail $detail): ?string
    {
        if ($detail?->weather_temp_c === null) {
            return null;
        }

        $label = "{$detail->weather_temp_c}°C";

        if ($detail->weather_wind_speed_kmh !== null) {
            $label .= ", angin {$detail->weather_wind_speed_kmh} km/j";
        }

        return $label;
    }

    private function formatKm(?float $distanceMeters): string
    {
        if ($distanceMeters === null) {
            return '0';
        }

        $km = number_format($distanceMeters / 1000, 2, '.', '');

        return rtrim(rtrim($km, '0'), '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
