<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RunCard;
use App\Services\Geo\PolylineProjector;
use App\Services\Run\Story\RunCardImageRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Unauthenticated public views of a single run card. The HTML page (`show`) is
 * reached only via a server-minted signed URL (the `signed` middleware rejects a
 * missing/tampered signature with a 403), so possession of the link is the grant.
 * The PNG (`image`) is deliberately unsigned: OG crawlers and Telegram fetch it
 * without a signature, so card-id enumeration is an accepted trade for a share
 * asset. Both render a minimal standalone surface, not the authed Inertia shell.
 */
class PublicCardController extends Controller
{
    private const int SVG_SIZE = 320;

    private const int SVG_PAD = 24;

    public function __construct(
        private readonly PolylineProjector $projector,
        private readonly RunCardImageRenderer $imageRenderer,
    ) {}

    public function show(RunCard $card): View
    {
        $card->loadMissing('activity.detail');

        $detail = $card->activity->detail;
        $distanceKm = $detail?->distance !== null ? $detail->distance / 1000 : null;

        return view('public.kartu', [
            'name' => $card->special_move,
            'rarityLabel' => $card->rarity->label(),
            'rarityColor' => $card->rarity->hexColor(),
            'distanceKm' => $distanceKm,
            'date' => $detail?->start_date_local,
            'location' => $detail?->location_name,
            'routePath' => $this->projector->project($detail?->summary_polyline, self::SVG_SIZE, self::SVG_SIZE, self::SVG_PAD),
            'svgSize' => self::SVG_SIZE,
            'ogImage' => route('kartu.image', $card),
            'appUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    /**
     * The server-rendered share/OG card as a PNG. Memoised per card id +
     * updated_at so repeated crawler/Telegram fetches don't re-rasterise; a card
     * rebuild bumps updated_at and so mints a fresh key.
     */
    public function image(RunCard $card): Response
    {
        try {
            $png = Cache::remember(
                "kartu-image:{$card->id}:{$card->updated_at?->timestamp}",
                now()->addDay(),
                fn (): string => $this->imageRenderer->render($card),
            );
        } catch (\Throwable $e) {
            // A malformed SVG / Imagick hiccup must not surface a raw 500 to an
            // OG crawler or Telegram preview: log and 404 so the share degrades
            // to no-image rather than a broken page.
            report($e);
            abort(404);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
