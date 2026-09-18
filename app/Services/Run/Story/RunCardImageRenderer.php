<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\RunCard;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\CardFacts;
use App\Services\Run\Story\Card\CardOptions;
use App\Services\Run\Story\Card\CardStyle;
use App\Services\Run\Story\Card\CardStyleRenderer;
use App\Services\Run\Story\Card\Styles\BroadsheetRenderer;
use App\Services\Run\Story\Card\Styles\TicketRenderer;
use App\Services\Run\Story\Card\Styles\TopoPlateRenderer;
use Imagick;
use ImagickPixel;

/**
 * The only share-card renderer. Resolves the run's facts once, hands them to
 * the chosen print style, and rasterises the style's SVG through librsvg via
 * Imagick at the aspect's exact pixel size.
 *
 * The client used to carry a canvas port of the same card; it is gone, and both
 * the download and the Telegram photo now come from here, so the two cannot
 * drift apart.
 */
class RunCardImageRenderer
{
    /**
     * Renders at twice the export size and samples back down. librsvg's own
     * antialiasing is fine, but the styles set a lot of 1-2px rules and small
     * mono caps, and those survive the downsample noticeably cleaner.
     */
    private const int SUPERSAMPLE_DPI = 192;

    public function __construct(
        private readonly BroadsheetRenderer $broadsheet,
        private readonly TicketRenderer $ticket,
        private readonly TopoPlateRenderer $topoPlate,
    ) {
    }

    /** PNG bytes for the given card, style, aspect and optional-fact toggles. */
    public function render(
        RunCard $card,
        CardStyle $style = CardStyle::Broadsheet,
        CardAspect $aspect = CardAspect::Story,
        ?CardOptions $options = null,
    ): string {
        return $this->rasterise($this->buildSvg($card, $style, $aspect, $options), $aspect);
    }

    /**
     * The card's SVG source. Public because it is what the renderer's tests
     * assert on: the structure of the drawing is the thing under test, and a
     * pixel diff across rasteriser versions is not.
     */
    public function buildSvg(
        RunCard $card,
        CardStyle $style = CardStyle::Broadsheet,
        CardAspect $aspect = CardAspect::Story,
        ?CardOptions $options = null,
    ): string {
        $facts = CardFacts::from($card, $options ?? new CardOptions());

        return $this->styleRenderer($style)->render($facts, $aspect);
    }

    private function styleRenderer(CardStyle $style): CardStyleRenderer
    {
        return match ($style) {
            CardStyle::Broadsheet => $this->broadsheet,
            CardStyle::Ticket => $this->ticket,
            CardStyle::TopoPlate => $this->topoPlate,
        };
    }

    private function rasterise(string $svg, CardAspect $aspect): string
    {
        $imagick = new Imagick();

        try {
            $imagick->setBackgroundColor(new ImagickPixel('transparent'));
            $imagick->setResolution(self::SUPERSAMPLE_DPI, self::SUPERSAMPLE_DPI);
            $imagick->readImageBlob($svg);
            $imagick->resizeImage(CardAspect::WIDTH, $aspect->height(), Imagick::FILTER_LANCZOS, 1);
            $imagick->setImageFormat('png');

            return $imagick->getImageBlob();
        } finally {
            // Free the MagickWand C resources even if a read/encode throws, so a
            // bad SVG can't leak memory across the long-lived Octane worker.
            $imagick->clear();
        }
    }
}
