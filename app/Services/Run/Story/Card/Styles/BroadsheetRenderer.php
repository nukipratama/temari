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
 * Style A — BROADSHEET. An editorial poster: a thin masthead rule carrying the
 * wordmark and rarity word, one enormous Fraunces-italic figure set over a
 * ghosted drawing of the route, and a ruled stat baseline in mono.
 *
 * Form follows the run by changing which number is the hero and how much air
 * the route gets; rarity escalates in additive chrome — a rarity word, then an
 * edge bar, a coloured band, a diagonal wedge with a misregistered echo behind
 * the figure, and finally a whole-card wash.
 */
final readonly class BroadsheetRenderer implements CardStyleRenderer
{
    /**
     * Hero display sizes by character count. Fraunces is proportional and PHP
     * cannot measure it, so the figure steps down a fixed ladder as it grows
     * rather than being fitted to a guessed advance.
     */
    private const array HERO_FIT = [4 => 1.0, 5 => 0.8, 6 => 0.66];

    private const float HERO_FIT_FLOOR = 0.56;

    public function __construct(private PolylineProjector $projector)
    {
    }

    public function render(CardFacts $facts, CardAspect $aspect): string
    {
        $story = $aspect->isStory();
        $width = CardAspect::WIDTH;
        $height = $aspect->height();
        $margin = $story ? 84.0 : 76.0;
        $top = $story ? 300.0 : 132.0;
        $bottom = $story ? 1600.0 : 972.0;
        $safeTop = $story ? (float) CardAspect::SAFE_TOP : 0.0;
        $safeBottom = $story ? (float) CardAspect::SAFE_BOTTOM : (float) $height;

        $rarity = $facts->rarity->hexColor();
        $level = $facts->level();
        $isPr = $facts->form === RunForm::Pr;
        $isRace = $facts->form === RunForm::Race;
        $isLong = $facts->form === RunForm::Long;
        $ground = $isPr ? Svg::PR_GROUND : Svg::SKY_DEEP;

        $out = Svg::rect(0, 0, $width, $height, fill: $ground);

        if ($level >= 4) {
            $out .= Svg::path(sprintf(
                'M0,%s L0,%s L%s,%s L%s,%s Z',
                Svg::num($height),
                Svg::num($height * 0.42),
                Svg::num($width),
                Svg::num($height * 0.14),
                Svg::num($width),
                Svg::num($height),
            ), fill: $rarity, opacity: 0.1);
        }
        if ($level >= 2) {
            $out .= Svg::rect(0, $safeTop, 10, $safeBottom - $safeTop, fill: $rarity);
        }
        if ($level >= 5) {
            $out .= Svg::rect(0, 0, $width, $height, fill: $rarity, opacity: 0.06);
        }

        $out .= $isRace
            ? $this->raceBand($facts, $width, $margin, $safeTop, $rarity)
            : $this->masthead($facts, $width, $margin, $top, $rarity);

        $routeTop = $story ? ($isRace ? $top + 150 : $top + 118) : $top + 70;
        $routeHeight = $story ? ($isLong ? 880.0 : 700.0) : 420.0;
        $routeX = $margin + ($isLong ? -$margin : 40);
        $routeWidth = $width - 2 * $margin + ($isLong ? 2 * $margin : -80);

        $out .= $facts->hasRoute()
            ? $this->route($facts, $routeX, $routeTop, $routeWidth, $routeHeight, $rarity, $ground)
            : $this->noSignalBelt($width, $margin, $routeTop, $routeHeight, $story);

        $out .= $this->hero($facts, $width, $margin, $story, $bottom);

        if ($isRace && $story && $facts->splits !== []) {
            $out .= $this->splits($facts, $width, $margin, $routeTop);
        }
        if ($isLong) {
            $out .= Svg::text(
                'LONG RUN',
                0,
                0,
                26,
                Svg::CREAM,
                tracking: 12,
                opacity: 0.35,
                transform: sprintf('translate(%s,%s) rotate(90)', Svg::num($width - 32), $story ? '620' : '400')
            );
        }

        $out .= $this->statBaseline($facts, $width, $margin, $bottom, $story);
        $out .= $this->footer($facts, $width, $margin, $bottom, $story, $rarity, $isRace);

        return Svg::doc($width, $height, Svg::group($out));
    }

    private function masthead(CardFacts $facts, float $width, float $margin, float $top, string $rarity): string
    {
        $meta = $facts->weather === null
            ? Svg::clip($facts->placeShort, 34)
            : Svg::clip($facts->placeShort, 20).' · '.mb_strtoupper(Svg::clip($facts->weather, 22));

        return Svg::wordmark($margin, $top, 54, Svg::HORIZON)
            .Svg::text(
                $facts->rarity->symbol().'  '.mb_strtoupper($facts->rarity->label()),
                $width - $margin,
                $top - 6,
                24,
                $rarity,
                weight: 600,
                anchor: 'end',
                tracking: 4
            )
            .Svg::line($margin, $top + 30, $width - $margin, $top + 30, Svg::CREAM, width: 2, opacity: 0.22)
            .Svg::text($meta, $margin, $top + 74, 23, Svg::CREAM, tracking: 2, opacity: 0.62)
            .Svg::text(
                $facts->dateLong,
                $width - $margin,
                $top + 74,
                23,
                Svg::CREAM,
                anchor: 'end',
                tracking: 2,
                opacity: 0.62
            );
    }

    /** A race replaces the masthead with a full-width rarity band. */
    private function raceBand(CardFacts $facts, float $width, float $margin, float $bandTop, string $rarity): string
    {
        $ink = Svg::readableInk($rarity);

        return Svg::rect(0, $bandTop, $width, 104, fill: $rarity)
            .Svg::text(Svg::clip((string) $facts->raceName, 26), $margin, $bandTop + 68, 34, $ink, weight: 700, tracking: 5)
            .Svg::text((string) $facts->raceDistance, $width - $margin, $bandTop + 68, 34, $ink, weight: 700, anchor: 'end')
            .Svg::text('BIB '.$facts->bib(), $margin, $bandTop + 150, 24, Svg::INK_ON_SKY, tracking: 3);
    }

    private function route(CardFacts $facts, float $x, float $y, float $width, float $height, string $rarity, string $ground): string
    {
        $points = $this->projector->points($facts->polyline, $width, $height, 0);
        if ($points === null) {
            return '';
        }

        $points = array_map(fn (array $p): array => [$p[0] + $x, $p[1] + $y], $points);
        $closed = Svg::isClosedLoop($points, $width, $height);
        $heavy = $facts->form === RunForm::Long;
        $colour = match ($facts->form) {
            RunForm::Pr => Svg::CITRUS,
            RunForm::Race => $rarity,
            default => Svg::CREAM,
        };

        $svg = Svg::path(
            Svg::polylinePath($points, close: $closed),
            stroke: $colour,
            strokeWidth: $heavy ? 16 : 11,
            strokeOpacity: $heavy ? 0.5 : ($facts->form === RunForm::Easy ? 0.2 : 0.38)
        );

        $first = $points[0];
        $svg .= Svg::circle($first[0], $first[1], 15, fill: Svg::HORIZON);
        if (! $closed) {
            $last = $points[count($points) - 1];
            $svg .= Svg::circle($last[0], $last[1], 15, fill: $rarity);
        }
        if ($heavy) {
            foreach (Svg::along($points, 3) as [$mx, $my]) {
                $svg .= Svg::circle($mx, $my, 9, fill: $ground, stroke: Svg::CREAM, strokeWidth: 4, strokeOpacity: 0.6);
            }
        }

        return $svg;
    }

    /** No GPS: the map area becomes a typographic belt. */
    private function noSignalBelt(float $width, float $margin, float $top, float $height, bool $story): string
    {
        $rows = $story ? 11 : 6;
        $gap = ($height * ($story ? 1 : 0.78)) / $rows;
        $svg = '';

        for ($i = 0; $i < $rows; $i++) {
            $y = $top + 40 + $i * $gap;
            $svg .= Svg::text(
                'NO GPS · NO ROUTE · NO GPS · NO ROUTE · NO GPS',
                $margin - 20,
                $y,
                30,
                Svg::CREAM,
                tracking: 2,
                opacity: $i % 2 === 0 ? 0.13 : 0.07
            );
            $svg .= Svg::line($margin - 20, $y + 14, $width - $margin + 20, $y + 14, Svg::CREAM, width: 1, opacity: 0.08);
        }

        return $svg;
    }

    /**
     * One enormous figure: distance normally, finish time on a race or a PR.
     * The unit mark is right-anchored at the far margin rather than trailing
     * the figure, since nothing here can measure where the figure ends.
     */
    private function hero(CardFacts $facts, float $width, float $margin, bool $story, float $bottom): string
    {
        $heroIsTime = $facts->form === RunForm::Race || $facts->form === RunForm::Pr;
        $value = $heroIsTime ? $facts->time : $facts->km;
        $base = $story
            ? ($heroIsTime ? 268.0 : ($facts->form === RunForm::Long ? 320.0 : 400.0))
            : ($heroIsTime ? 160.0 : 232.0);
        $size = $base * (self::HERO_FIT[mb_strlen($value)] ?? self::HERO_FIT_FLOOR);
        $baseline = $story
            ? match ($facts->form) {
                RunForm::Pr => 1300.0,
                RunForm::Long => 1390.0,
                default => 1370.0,
            }
        : 742.0;

        $svg = Svg::text(
            $facts->kind,
            $margin,
            $baseline - $base * 0.82,
            $story ? 30 : 24,
            $facts->form === RunForm::Pr ? Svg::CITRUS : Svg::HORIZON,
            weight: 600,
            tracking: 7
        );

        if ($facts->level() >= 4) {
            $svg .= Svg::text(
                $value,
                $margin + 10,
                $baseline + 8,
                $size,
                $facts->rarity->hexColor(),
                family: Svg::DISPLAY,
                weight: 600,
                italic: true,
                opacity: 0.55
            );
        }
        $svg .= Svg::text(
            $value,
            $margin,
            $baseline,
            $size,
            Svg::CREAM,
            family: Svg::DISPLAY,
            weight: 600,
            italic: true
        );

        if (! $heroIsTime) {
            $svg .= Svg::text(
                'KM',
                $width - $margin,
                $baseline,
                $story ? 62 : 44,
                Svg::CREAM,
                weight: 600,
                anchor: 'end',
                opacity: 0.55
            );
        }

        return $svg;
    }

    private function splits(CardFacts $facts, float $width, float $margin, float $top): string
    {
        $y = $top + 30;
        $svg = Svg::text('SPLITS', $width - $margin, $y, 22, Svg::INK_ON_SKY, anchor: 'end', tracking: 5);

        foreach ($facts->splits as [$label, $value]) {
            $y += 44;
            $svg .= Svg::text($label.'  '.$value, $width - $margin, $y, 30, Svg::CREAM, anchor: 'end');
        }

        return $svg;
    }

    private function statBaseline(CardFacts $facts, float $width, float $margin, float $bottom, bool $story): string
    {
        $cells = $facts->statCells();
        if ($facts->form === RunForm::Race || $facts->form === RunForm::Pr) {
            $cells[0] = ['DISTANCE', $facts->km.' km'];
        }

        $top = $bottom - ($story ? 190 : 176);
        $svg = Svg::line($margin, $top, $width - $margin, $top, Svg::CREAM, width: 2, opacity: 0.22);
        $cellWidth = ($width - 2 * $margin) / count($cells);

        foreach ($cells as $i => [$label, $value]) {
            $x = $margin + $i * $cellWidth;
            if ($i > 0) {
                $svg .= Svg::line($x - 24, $top + 18, $x - 24, $top + ($story ? 150 : 120), Svg::CREAM, width: 1, opacity: 0.16);
            }
            $svg .= Svg::text($label, $x, $top + ($story ? 56 : 46), $story ? 22 : 19, Svg::CREAM, tracking: 4, opacity: 0.55);
            $svg .= Svg::text($value, $x, $top + ($story ? 122 : 104), $story ? 54 : 42, Svg::CREAM, weight: 600);
        }

        return $svg;
    }

    private function footer(CardFacts $facts, float $width, float $margin, float $bottom, bool $story, string $rarity, bool $isRace): string
    {
        $y = $bottom - ($story ? 10 : 6);
        $size = $story ? 22.0 : 18.0;
        $svg = '';

        if ($facts->badges === []) {
            $svg .= Svg::text($facts->serial, $margin, $y, $size, Svg::CREAM, tracking: 3, opacity: 0.35);
        } else {
            $x = $margin;
            $height = $story ? 54.0 : 46.0;
            foreach ($facts->badges as $badge) {
                $label = mb_strtoupper($badge);
                $chip = Svg::monoWidth($label, $size, 2) + 44;
                $svg .= Svg::rect($x, $y - ($story ? 46 : 38), $chip, $height, radius: $height / 2, stroke: $rarity, strokeWidth: 2);
                $svg .= Svg::text($label, $x + 22, $y - ($story ? 10 : 8), $size, $rarity, tracking: 2);
                $x += $chip + 18;
            }
        }

        return $svg.($isRace
            ? Svg::wordmark($width - $margin, $y, $story ? 42 : 34, Svg::HORIZON, anchor: 'end')
            : Svg::text($facts->dateShort, $width - $margin, $y, $size, Svg::CREAM, anchor: 'end', tracking: 3, opacity: 0.35));
    }

}
