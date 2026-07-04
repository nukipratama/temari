<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Geo\PolylineProjector;
use Imagick;
use ImagickPixel;

/**
 * Renders a branded, rarity-tinted "poster" PNG for a run card, used both as the
 * public card page's OG image and as the photo attached to the post-run Telegram
 * notification. Builds a self-contained landscape SVG (no external fonts/images,
 * literal hex colors, generic font-family) and rasterises it via Imagick +
 * librsvg. The route polyline is the hero when present; a no-GPS card degrades to
 * a route-less layout that leans on the distance figure instead.
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

    private const string CREAM_DEEP = '#eee7d6';

    private const string SURFACE = '#fbf7ee';

    private const string SKY = '#1f2747';

    private const string INK = '#1a1812';

    private const string INK_MUTED = '#6e6452';

    private const string LINE = '#d8ddd2';

    public function __construct(private readonly PolylineProjector $projector) {}

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
        $location = $detail?->location_name;
        $weather = $this->weatherLabel($detail);
        $metaLine = $this->escape(implode('  ·  ', array_filter([$dateLabel, $location, $weather])));

        $routePoints = $this->projector->project(
            $detail?->summary_polyline,
            self::PANEL_W,
            self::PANEL_H,
            self::PANEL_PAD,
        );
        $panel = $this->routePanel($routePoints, $rarity);

        [$cream, $creamDeep, $surface, $sky, $ink, $inkMuted, $line] = [
            self::CREAM, self::CREAM_DEEP, self::SURFACE, self::SKY, self::INK, self::INK_MUTED, self::LINE,
        ];

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630" font-family="sans-serif">
  <rect width="1200" height="630" fill="{$creamDeep}"/>
  <rect x="40" y="40" width="1120" height="550" rx="36" fill="{$surface}" stroke="{$line}" stroke-width="2"/>
  <rect x="40" y="40" width="1120" height="14" rx="7" fill="{$rarity}"/>

  <text x="88" y="150" font-size="26" font-weight="700" letter-spacing="6" fill="{$rarity}">{$rarityLabel}</text>
  <text x="86" y="248" font-size="76" font-weight="700" fill="{$ink}">{$name}</text>

  <text x="88" y="430" font-size="150" font-weight="700" fill="{$sky}">{$km}</text>
  <text x="90" y="480" font-size="30" font-weight="700" letter-spacing="4" fill="{$inkMuted}">KILOMETER</text>

  <text x="90" y="536" font-size="28" fill="{$inkMuted}">{$metaLine}</text>

  {$panel}

  <text x="88" y="576" font-size="26" font-weight="700" fill="{$sky}">teman-lari</text>
  <text x="248" y="576" font-size="26" fill="{$inkMuted}">· teman lari kamu.</text>
</svg>
SVG;
    }

    /**
     * The right-hand hero panel: the fitted route polyline, or a "no route"
     * placeholder when the card has no drawable GPS track.
     */
    private function routePanel(?string $points, string $rarity): string
    {
        [$x, $y, $w, $h, $cream, $line, $inkMuted] = [
            self::PANEL_X, self::PANEL_Y, self::PANEL_W, self::PANEL_H, self::CREAM, self::LINE, self::INK_MUTED,
        ];

        $frame = <<<SVG
<rect x="{$x}" y="{$y}" width="{$w}" height="{$h}" rx="24" fill="{$cream}" stroke="{$line}" stroke-width="2"/>
SVG;

        if ($points === null) {
            $cx = $x + intdiv($w, 2);
            $cy = $y + intdiv($h, 2);

            return $frame . <<<SVG

  <text x="{$cx}" y="{$cy}" font-size="26" fill="{$inkMuted}" text-anchor="middle">Rute tidak tersedia</text>
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
        $imagick->setBackgroundColor(new ImagickPixel('transparent'));
        $imagick->setResolution(144, 144);
        $imagick->readImageBlob($svg);
        $imagick->setImageFormat('png');
        $png = $imagick->getImageBlob();
        $imagick->clear();

        return $png;
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
