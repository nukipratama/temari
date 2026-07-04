<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RunCard;
use App\Services\Geo\PolylineDecoder;
use Illuminate\Contracts\View\View;

/**
 * Unauthenticated, signed public view of a single run card. Reached only via a
 * server-minted signed URL (the `signed` middleware rejects a missing/tampered
 * signature with a 403), so no ownership check is needed: possession of the link
 * is the grant. Renders a minimal standalone Blade page with social meta, not
 * the authed Inertia app shell.
 */
class PublicCardController extends Controller
{
    private const int SVG_SIZE = 320;

    private const int SVG_PAD = 24;

    public function __construct(private readonly PolylineDecoder $decoder) {}

    public function show(RunCard $card): View
    {
        $card->loadMissing('activity.detail');

        $detail = $card->activity->detail;
        $distanceKm = $detail?->distance !== null ? $detail->distance / 1000 : null;

        return view('public.kartu', [
            'name' => $card->special_move,
            'rarityLabel' => $card->rarity->label(),
            'rarity' => $card->rarity->value,
            'distanceKm' => $distanceKm,
            'date' => $detail?->start_date_local,
            'location' => $detail?->location_name,
            'routePath' => $this->routeSvgPath($detail?->summary_polyline),
            'svgSize' => self::SVG_SIZE,
            'appUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    /**
     * Project the encoded polyline into an SVG polyline `points` string fitted to
     * a padded square box (north up), mirroring the client RouteGlyph projection.
     * Returns null when there's nothing drawable.
     */
    private function routeSvgPath(?string $polyline): ?string
    {
        if ($polyline === null || $polyline === '') {
            return null;
        }

        $points = $this->decoder->decode($polyline);
        if (\count($points) < 2) {
            return null;
        }

        $lats = array_column($points, 0);
        $lngs = array_column($points, 1);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        $spanLat = ($maxLat - $minLat) ?: 1;
        $spanLng = ($maxLng - $minLng) ?: 1;

        $inner = self::SVG_SIZE - self::SVG_PAD * 2;
        $scale = min($inner / $spanLng, $inner / $spanLat);
        $offX = self::SVG_PAD + ($inner - $spanLng * $scale) / 2;
        $offY = self::SVG_PAD + ($inner - $spanLat * $scale) / 2;

        $coords = array_map(
            fn (array $p): string => sprintf(
                '%.1f,%.1f',
                $offX + ($p[1] - $minLng) * $scale,
                $offY + ($maxLat - $p[0]) * $scale, // flip y: higher latitude = higher on screen
            ),
            $points,
        );

        return implode(' ', $coords);
    }
}
