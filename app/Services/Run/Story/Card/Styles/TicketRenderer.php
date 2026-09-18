<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card\Styles;

use App\Services\Geo\PolylineProjector;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\CardFacts;
use App\Services\Run\Story\Card\CardStyleRenderer;
use App\Services\Run\Story\Card\RunForm;
use App\Services\Run\Story\Card\Svg;

/**
 * Style B — TICKET. Printed stock: an ink band across the top, ruled boxes for
 * every figure, a perforated tear and a stub carrying the place, date, weather,
 * wordmark and rarity dots. Mono throughout; Fraunces appears only in the
 * wordmark on the stub.
 *
 * Form follows the run structurally — a race stops being a ticket and becomes a
 * bib — and rarity escalates through print finishing: band ink, a second ruled
 * border, a foil strip, a second perforation row.
 */
final readonly class TicketRenderer implements CardStyleRenderer
{
    public function __construct(private PolylineProjector $projector)
    {
    }

    public function render(CardFacts $facts, CardAspect $aspect): string
    {
        $story = $aspect->isStory();
        $width = CardAspect::WIDTH;
        $height = $aspect->height();
        $rarity = $facts->rarity->hexColor();
        $level = $facts->level();

        $tx = $story ? 58.0 : 46.0;
        $ty = $story ? (float) CardAspect::SAFE_TOP + 2 : 46.0;
        $tw = $width - $tx * 2;
        $th = $story ? 1368.0 : $height - $ty * 2;

        $out = Svg::rect(0, 0, $width, $height, fill: Svg::CREAM_DEEP);
        $out .= sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="%s" filter="url(#ticket-shadow)"/>',
            Svg::num($tx),
            Svg::num($ty),
            Svg::num($tw),
            Svg::num($th),
            Svg::CREAM
        );
        if ($level >= 3) {
            $out .= Svg::rect($tx + 14, $ty + 14, $tw - 28, $th - 28, stroke: Svg::LINE, strokeWidth: 2);
        }

        $bandHeight = $story ? 96.0 : 84.0;
        $bandInk = Svg::readableInk($rarity);
        $out .= Svg::rect($tx, $ty, $tw, $bandHeight, fill: $rarity);
        if ($level >= 4) {
            $out .= Svg::rect($tx, $ty + $bandHeight, $tw, 10, fill: 'url(#ticket-foil)');
        }
        $out .= Svg::text(
            Svg::clip($facts->form === RunForm::Race ? (string) $facts->raceName : $facts->kind, 24),
            $tx + 30,
            $ty + $bandHeight * 0.65,
            $story ? 36 : 32,
            $bandInk,
            weight: 700,
            tracking: 5,
        );
        $out .= Svg::text(
            $facts->serial,
            $tx + $tw - 30,
            $ty + $bandHeight * 0.65,
            $story ? 28 : 24,
            $bandInk,
            anchor: 'end',
            tracking: 3
        );

        $pad = $story ? 42.0 : 36.0;
        $bx = $tx + $pad;
        $bw = $tw - $pad * 2;
        $cy = $ty + $bandHeight + ($story ? 46 : 36);
        $perfY = $ty + $th - ($story ? 250.0 : 196.0);

        $out .= match (true) {
            $facts->form === RunForm::Race => $this->bib($facts, $tx, $ty, $tw, $bandHeight, $bx, $bw, $cy, $perfY, $story, $rarity),
            $story => $this->storyBody($facts, $bx, $bw, $cy, $perfY, $rarity),
            default => $this->feedBody($facts, $bx, $bw, $cy, $perfY, $rarity),
        };

        $out .= $this->perforation($tx, $perfY, $tw);
        if ($level >= 5) {
            $out .= $this->perforation($tx, $perfY + 26, $tw);
        }
        $out .= $this->stub($facts, $tx, $tw, $bx, $pad, $perfY, $story, $rarity);

        if ($facts->form === RunForm::Pr) {
            $out .= $this->overstamp($tx, $tw, $perfY, $story);
        }

        return Svg::doc($width, $height, Svg::group($out), $this->defs($rarity));
    }

    /** A race turns the chassis into a bib: pin holes and one enormous number. */
    private function bib(
        CardFacts $facts,
        float $tx,
        float $ty,
        float $tw,
        float $bandHeight,
        float $bx,
        float $bw,
        float $cy,
        float $perfY,
        bool $story,
        string $rarity,
    ): string {
        $svg = '';
        foreach ([
            [$tx + 46, $ty + $bandHeight + 40],
            [$tx + $tw - 46, $ty + $bandHeight + 40],
            [$tx + 46, $perfY - 40],
            [$tx + $tw - 46, $perfY - 40],
        ] as [$px, $py]) {
            $svg .= Svg::circle($px, $py, 15, fill: Svg::CREAM_DEEP, stroke: Svg::LINE, strokeWidth: 2);
        }

        $bibY = $cy + ($story ? 300 : 210);
        $svg .= Svg::text($facts->bib(), $tx + $tw / 2, $bibY, $story ? 340 : 240, Svg::INK, weight: 800, anchor: 'middle');
        $svg .= Svg::text(
            (string) $facts->raceDistance,
            $tx + $tw / 2,
            $bibY + ($story ? 76 : 58),
            $story ? 54 : 42,
            $rarity,
            weight: 700,
            anchor: 'middle',
            tracking: 14
        );

        $cells = $facts->statCells();
        $cells[0] = ['FINISH', $facts->time];
        $cellHeight = $story ? 132.0 : 116.0;
        $cy = $bibY + ($story ? 130 : 96);
        $svg .= $this->cells($bx, $cy, $bw, $cellHeight, $cells);
        $cy += $cellHeight + ($story ? 34 : 24);

        if ($story) {
            return $svg.$this->window($facts, $bx, $cy, $bw, $perfY - $cy - 46, $rarity);
        }

        // No splits, no panel: the window is the block that always has
        // something to draw, even if that something is the cancelled hatch.
        return $svg.($facts->splits === []
            ? $this->window($facts, $bx, $cy, $bw, $perfY - $cy - 30, $rarity)
            : $this->chipSplits($facts, $bx, $cy, $bw, $perfY - $cy - 30));
    }

    private function storyBody(CardFacts $facts, float $bx, float $bw, float $cy, float $perfY, string $rarity): string
    {
        $heroHeight = 300.0;
        $svg = Svg::rect($bx, $cy, $bw, $heroHeight, fill: Svg::SURFACE_ELEV, stroke: Svg::LINE, strokeWidth: 2);
        $svg .= Svg::text('DISTANCE', $bx + 24, $cy + 46, 22, Svg::INK_3, tracking: 5);
        $svg .= Svg::text(
            $facts->km,
            $bx + 24,
            $cy + $heroHeight - 44,
            $this->figureSize(224, $facts->km, $bw - 150),
            Svg::INK,
            weight: 800
        );
        $svg .= Svg::text(
            'KM',
            $bx + $bw - 24,
            $cy + $heroHeight - 52,
            62,
            Svg::INK_3,
            weight: 700,
            anchor: 'end',
            tracking: 4
        );

        if ($facts->form === RunForm::Pr) {
            $svg .= Svg::rect($bx + $bw - 250, $cy + 26, 226, 76, fill: Svg::CITRUS, radius: 8);
            $svg .= Svg::text('NEW BEST', $bx + $bw - 137, $cy + 76, 30, Svg::INK, weight: 700, anchor: 'middle');
        }
        $cy += $heroHeight + 30;

        $svg .= $this->cells($bx, $cy, $bw, 138, $facts->statCells());
        $cy += 168;

        $windowHeight = $perfY - $cy - ($facts->badges === [] ? 44 : 110);
        $svg .= $this->window($facts, $bx, $cy, $bw, $windowHeight, $facts->form === RunForm::Pr ? Svg::CITRUS : $rarity);
        $cy += $windowHeight + 26;

        foreach ($facts->badges as $badge) {
            $label = mb_strtoupper($badge);
            $chip = Svg::monoWidth($label, 22, 2) + 46;
            $svg .= Svg::rect($bx, $cy, $chip, 58, radius: 6, stroke: $rarity, strokeWidth: 3);
            $svg .= Svg::text($label, $bx + 23, $cy + 39, 22, $facts->rarity->inkColor(), tracking: 2);
            $bx += $chip + 16;
        }

        return $svg;
    }

    /** Feed: figures in the left column, the route window in the right. */
    private function feedBody(CardFacts $facts, float $bx, float $bw, float $cy, float $perfY, string $rarity): string
    {
        $columnWidth = $bw * 0.46;
        $svg = Svg::text('DISTANCE', $bx, $cy + 30, 20, Svg::INK_3, tracking: 5);
        $svg .= Svg::text(
            $facts->km,
            $bx - 6,
            $cy + 168,
            $this->figureSize(168, $facts->km, $columnWidth + 24),
            Svg::INK,
            weight: 800
        );
        $svg .= Svg::text('KM', $bx, $cy + 218, 40, Svg::INK_3, weight: 700, tracking: 6);

        if ($facts->form === RunForm::Pr) {
            $svg .= Svg::rect($bx, $cy + 244, $columnWidth - 10, 62, fill: Svg::CITRUS, radius: 6);
            $svg .= Svg::text(
                'NEW BEST',
                $bx + ($columnWidth - 10) / 2,
                $cy + 286,
                28,
                Svg::INK,
                weight: 700,
                anchor: 'middle'
            );
        }

        $rowTop = $cy + ($facts->form === RunForm::Pr ? 322 : 250);
        foreach ([['TIME', $facts->time], ['PACE', $facts->pace]] as $i => [$label, $value]) {
            $y = $rowTop + $i * 96;
            $svg .= Svg::line($bx, $y, $bx + $columnWidth, $y, Svg::LINE, width: 2);
            $svg .= Svg::text($label, $bx, $y + 32, 20, Svg::INK_3, tracking: 4);
            $svg .= Svg::text($value, $bx + $columnWidth, $y + 74, 48, Svg::INK, weight: 700, anchor: 'end');
        }

        return $svg.$this->window(
            $facts,
            $bx + $columnWidth + 26,
            $cy,
            $bw - $columnWidth - 26,
            $perfY - $cy - 40,
            $facts->form === RunForm::Pr ? Svg::CITRUS : $rarity
        );
    }

    /**
     * The route printed as an inset window with frame ticks, or cancelled with
     * a diagonal hatch and a band when the run has no trace.
     */
    private function window(CardFacts $facts, float $x, float $y, float $width, float $height, string $accent): string
    {
        $svg = Svg::rect($x, $y, $width, $height, fill: Svg::SURFACE_ELEV, stroke: Svg::LINE, strokeWidth: 2);
        for ($i = 1; $i < 10; $i++) {
            $tick = $x + ($width / 10) * $i;
            $svg .= Svg::line($tick, $y, $tick, $y + 10, Svg::LINE, width: 2);
            $svg .= Svg::line($tick, $y + $height - 10, $tick, $y + $height, Svg::LINE, width: 2);
        }
        for ($i = 1; $i < 6; $i++) {
            $tick = $y + ($height / 6) * $i;
            $svg .= Svg::line($x, $tick, $x + 10, $tick, Svg::LINE, width: 2);
            $svg .= Svg::line($x + $width - 10, $tick, $x + $width, $tick, Svg::LINE, width: 2);
        }

        $pad = 44.0;
        $points = $this->projector->points($facts->polyline, $width - $pad * 2, $height - $pad * 2, 0);
        if ($points === null) {
            return $svg.$this->cancelled($x, $y, $width, $height);
        }

        $points = array_map(fn (array $p): array => [$p[0] + $x + $pad, $p[1] + $y + $pad], $points);
        $svg .= Svg::path(
            Svg::polylinePath($points, close: Svg::isClosedLoop($points, $width, $height)),
            stroke: $accent,
            strokeWidth: 9,
        );
        $svg .= Svg::circle($points[0][0], $points[0][1], 12, fill: Svg::SURFACE_ELEV, stroke: Svg::INK, strokeWidth: 5);
        $svg .= Svg::text('ROUTE', $x + 16, $y + $height - 18, 20, Svg::INK_3, tracking: 4);
        $svg .= Svg::text(
            Svg::clip($facts->placeShort, 22),
            $x + $width - 16,
            $y + $height - 18,
            20,
            Svg::INK_3,
            anchor: 'end',
            tracking: 2
        );

        return $svg;
    }

    private function cancelled(float $x, float $y, float $width, float $height): string
    {
        $svg = '';
        for ($i = -$height; $i < $width; $i += 26) {
            $svg .= Svg::line($x + $i, $y + $height, $x + $i + $height, $y, Svg::LINE, width: 3, opacity: 0.55);
        }

        $label = 'NO SIGNAL · NO ROUTE';
        $size = min(34.0, ($width - 120) / Svg::monoWidth($label, 1.0));
        $svg .= Svg::rect(
            $x + 20,
            $y + $height / 2 - 42,
            $width - 40,
            84,
            fill: Svg::SURFACE_ELEV,
            stroke: Svg::EMBER,
            strokeWidth: 3
        );

        return $svg.Svg::text(
            $label,
            $x + $width / 2,
            $y + $height / 2 + 12,
            $size,
            Svg::EMBER_INK,
            weight: 700,
            anchor: 'middle'
        );
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items
     */
    private function cells(float $x, float $y, float $width, float $height, array $items): string
    {
        $items = Svg::filledCells($items);
        if ($items === []) {
            return '';
        }

        $svg = Svg::rect($x, $y, $width, $height, stroke: Svg::LINE, strokeWidth: 2);
        $cellWidth = $width / count($items);

        foreach ($items as $i => [$label, $value]) {
            $cx = $x + $i * $cellWidth;
            if ($i > 0) {
                $svg .= Svg::line($cx, $y, $cx, $y + $height, Svg::LINE, width: 2);
            }
            $svg .= Svg::text($label, $cx + 22, $y + 40, 20, Svg::INK_3, tracking: 4);
            $svg .= Svg::text($value, $cx + 22, $y + $height - 28, 52, Svg::INK, weight: 700);
        }

        return $svg;
    }

    private function chipSplits(CardFacts $facts, float $x, float $y, float $width, float $height): string
    {
        $svg = Svg::rect($x, $y, $width, $height, fill: Svg::SURFACE_ELEV, stroke: Svg::LINE, strokeWidth: 2);
        $svg .= Svg::text('CHIP SPLITS', $x + 20, $y + 36, 20, Svg::INK_3, tracking: 4);

        $cellWidth = ($width - 40) / count($facts->splits);
        foreach ($facts->splits as $i => [$label, $value]) {
            $sx = $x + 20 + $i * $cellWidth;
            if ($i > 0) {
                $svg .= Svg::line($sx - 12, $y + 50, $sx - 12, $y + $height - 18, Svg::LINE, width: 2);
            }
            $svg .= Svg::text($label, $sx, $y + 74, 20, Svg::INK_3);
            $svg .= Svg::text($value, $sx, $y + 112, 30, Svg::INK, weight: 700);
        }

        return $svg;
    }

    private function perforation(float $x, float $y, float $width): string
    {
        return Svg::line($x, $y, $x + $width, $y, Svg::LINE_STRONG, width: 2, dash: '10 12')
            .Svg::circle($x, $y, 20, fill: Svg::CREAM_DEEP)
            .Svg::circle($x + $width, $y, 20, fill: Svg::CREAM_DEEP);
    }

    private function stub(CardFacts $facts, float $tx, float $tw, float $bx, float $pad, float $perfY, bool $story, string $rarity): string
    {
        $y = $perfY + ($story ? 80 : 64);
        $meta = array_filter([$facts->dateLong, $facts->clock, mb_strtoupper((string) $facts->weather)]);

        $svg = Svg::wordmark($bx, $y + ($story ? 8 : 4), $story ? 54 : 46, Svg::HORIZON_INK);
        $svg .= Svg::text(
            mb_strtoupper(Svg::clip($facts->place, 38)),
            $bx,
            $y + ($story ? 66 : 54),
            $story ? 24 : 21,
            Svg::INK_2,
            tracking: 2
        );
        $svg .= Svg::text(
            Svg::clip(implode(' · ', $meta), 46),
            $bx,
            $y + ($story ? 108 : 88),
            $story ? 22 : 19,
            Svg::INK_3,
            tracking: 1
        );
        $svg .= Svg::text(
            mb_strtoupper($facts->rarity->label()),
            $tx + $tw - $pad,
            $y + ($story ? 8 : 4),
            $story ? 26 : 22,
            $facts->rarity->inkColor(),
            weight: 700,
            anchor: 'end',
            tracking: 4
        );

        for ($i = 0; $i < $facts->rarity->bandCount(); $i++) {
            $svg .= Svg::circle($tx + $tw - $pad - $i * 26, $y + ($story ? 44 : 36), 8, fill: $rarity);
        }

        return $svg;
    }

    /** A rotated PERSONAL RECORD stamp, printed across the paper. */
    private function overstamp(float $tx, float $tw, float $perfY, bool $story): string
    {
        $inner = Svg::rect(-226, -60, 452, 120, radius: 10, stroke: Svg::EMBER, strokeWidth: 7, strokeOpacity: 0.8)
            .Svg::text('PERSONAL RECORD', 0, 20, 50, Svg::EMBER, weight: 800, anchor: 'middle', tracking: 4, opacity: 0.8);

        return Svg::group($inner, transform: sprintf(
            'translate(%s,%s) rotate(-8) scale(%s)',
            Svg::num($tx + $tw * 0.52),
            Svg::num($perfY - ($story ? 320 : 150)),
            $story ? '0.95' : '0.58',
        ));
    }

    /**
     * A mono figure at its nominal size, stepped down only far enough to stay
     * inside the box. JetBrains Mono is monospaced, so this is arithmetic on a
     * known advance rather than a guess at the glyph's width.
     */
    private function figureSize(float $nominal, string $value, float $available): float
    {
        return min($nominal, $available / max(Svg::monoWidth($value, 1.0), 1.0));
    }

    private function defs(string $rarity): string
    {
        return '<filter id="ticket-shadow" x="-20%" y="-20%" width="140%" height="140%">'
            .'<feDropShadow dx="0" dy="14" stdDeviation="16" flood-color="'.Svg::SKY_DEEP.'" flood-opacity="0.18"/>'
            .'</filter>'
            .'<linearGradient id="ticket-foil" x1="0" y1="0" x2="1" y2="0">'
            .'<stop offset="0" stop-color="'.$rarity.'" stop-opacity="0.15"/>'
            .'<stop offset="0.5" stop-color="'.$rarity.'" stop-opacity="0.9"/>'
            .'<stop offset="1" stop-color="'.$rarity.'" stop-opacity="0.15"/>'
            .'</linearGradient>';
    }
}
