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
 * Style C — TOPO PLATE. A surveyed map sheet: a ticked collar, contour rings
 * under the trace, a scale bar and a north arrow, and a ruled title block. The
 * route is the subject rather than decoration, and the type is cartographic
 * throughout — mono at every size, uppercase, tracked out.
 *
 * Rarity escalates through the survey itself: seven contour rings at common
 * rising to fifteen at legendary, a hypsometric tint, the trace promoted to the
 * rarity colour and then thickened with a centre line, and a round survey stamp.
 */
final readonly class TopoPlateRenderer implements CardStyleRenderer
{
    public function __construct(private PolylineProjector $projector)
    {
    }

    public function render(CardFacts $facts, CardAspect $aspect): string
    {
        $story = $aspect->isStory();
        $width = CardAspect::WIDTH;
        $height = $aspect->height();
        $level = $facts->level();
        $rarity = $facts->rarity->hexColor();
        $rarityInk = $facts->rarity->inkColor();

        $px = $story ? 54.0 : 44.0;
        $py = $story ? (float) CardAspect::SAFE_TOP : 44.0;
        $pw = $width - $px * 2;
        $ph = $story ? 1378.0 : $height - $py * 2;

        $out = Svg::rect(0, 0, $width, $height, fill: Svg::CREAM_DEEP);
        $out .= Svg::rect($px, $py, $pw, $ph, fill: Svg::CREAM);
        $out .= $this->collar($px, $py, $pw, $ph);

        $out .= Svg::text(
            'PLATE '.$facts->bib().' · '.Svg::clip($facts->placeShort, 24),
            $px + 34,
            $py + 62,
            24,
            Svg::INK,
            weight: 600,
            tracking: 4
        );
        $out .= Svg::text(
            $facts->rarity->symbol().' '.mb_strtoupper($facts->rarity->label()),
            $px + $pw - 34,
            $py + 62,
            24,
            $rarityInk,
            weight: 600,
            anchor: 'end',
            tracking: 4
        );
        $out .= Svg::line($px + 34, $py + 80, $px + $pw - 34, $py + 80, Svg::INK, width: 2);

        $fx = $px + 34;
        $fy = $py + 96;
        $fw = $pw - 68;
        $titleHeight = $story
            ? match ($facts->form) {
                RunForm::Race => 420.0,
                RunForm::Long => 400.0,
                default => 330.0,
            }
        : 300.0;
        $fh = $ph - ($fy - $py) - $titleHeight - 34;

        $out .= sprintf(
            '<clipPath id="plate-field"><rect x="%s" y="%s" width="%s" height="%s"/></clipPath>',
            Svg::num($fx),
            Svg::num($fy),
            Svg::num($fw),
            Svg::num($fh)
        );
        $out .= Svg::rect($fx, $fy, $fw, $fh, fill: Svg::SURFACE_ELEV, stroke: Svg::LINE, strokeWidth: 2);

        // A polyline that decodes to fewer than two points is as route-less as
        // no polyline at all, so the survey itself decides which branch runs.
        $field = $this->survey($facts, $fx, $fy, $fw, $fh, $story, $level, $rarity)
            ?? $this->unsurveyed($facts, $fx, $fy, $fw, $fh, $story);
        $out .= Svg::group($field, clip: 'url(#plate-field)');

        $out .= $this->scaleBar($fx + 28, $fy + $fh - 44, $facts->form === RunForm::Long ? 4 : 2);
        $out .= $this->northArrow($fx + $fw - 48, $fy + 62);

        if ($level >= 4) {
            $out .= $this->surveyStamp($facts, $fx + $fw - 150, $fy + $fh - 150, $rarityInk);
        }

        $out .= $this->titleBlock($facts, $fx, $fy + $fh + 26, $fw, $story);
        $out .= $this->legend($facts, $fx, $fw, $py + $ph - ($story ? 40 : 34), $story, $rarityInk);

        return Svg::doc($width, $height, Svg::group($out));
    }

    private function collar(float $x, float $y, float $width, float $height): string
    {
        $svg = Svg::rect($x, $y, $width, $height, stroke: Svg::INK, strokeWidth: 5);
        $svg .= Svg::rect($x + 16, $y + 16, $width - 32, $height - 32, stroke: Svg::INK, strokeWidth: 2);

        for ($i = $x + 60; $i < $x + $width - 20; $i += 60) {
            $svg .= Svg::line($i, $y + 1, $i, $y + 16, Svg::INK, width: 2);
            $svg .= Svg::line($i, $y + $height - 16, $i, $y + $height - 1, Svg::INK, width: 2);
        }
        for ($i = $y + 60; $i < $y + $height - 20; $i += 60) {
            $svg .= Svg::line($x + 1, $i, $x + 16, $i, Svg::INK, width: 2);
            $svg .= Svg::line($x + $width - 16, $i, $x + $width - 1, $i, Svg::INK, width: 2);
        }

        return $svg;
    }

    private function survey(CardFacts $facts, float $fx, float $fy, float $fw, float $fh, bool $story, int $level, string $rarity): ?string
    {
        $pad = $story ? 70.0 : 56.0;
        $points = $this->projector->points($facts->polyline, $fw - $pad * 2, $fh - $pad * 2, 0);
        if ($points === null) {
            return null;
        }

        $cx = $fx + $fw / 2;
        $cy = $fy + $fh / 2;
        $seed = crc32((string) $facts->serial) % 997;
        $rings = 5 + $level * 2;
        $rx = $fw * 0.62;
        $ry = $fh * 0.62;

        $svg = '';
        for ($i = $rings; $i >= 1; $i--) {
            $t = $i / $rings;
            $svg .= Svg::path(
                Svg::closedLoop($cx, $cy, $rx * $t, $ry * $t, $seed + $i * 5),
                stroke: Svg::LEAF_INK,
                strokeWidth: $i % 3 === 0 ? 3.5 : 2,
                strokeOpacity: $i % 3 === 0 ? 0.42 : 0.26,
            );
        }
        if ($level >= 3) {
            $svg .= Svg::path(
                Svg::closedLoop($cx, $cy, $rx * 0.5, $ry * 0.5, $seed + 11, wobble: 0.14),
                fill: $rarity,
                opacity: 0.07
            );
        }

        $points = array_map(fn (array $p): array => [$p[0] + $fx + $pad, $p[1] + $fy + $pad], $points);
        $d = Svg::polylinePath($points, close: Svg::isClosedLoop($points, $fw, $fh));

        $traceColour = match (true) {
            $facts->form === RunForm::Pr => Svg::CITRUS,
            $level >= 3 => $rarity,
            default => Svg::INK,
        };
        $svg .= Svg::path($d, stroke: Svg::CREAM, strokeWidth: 22);
        $svg .= Svg::path($d, stroke: $traceColour, strokeWidth: $level >= 4 ? 13 : 10);
        if ($level >= 4) {
            $svg .= Svg::path($d, stroke: Svg::CREAM, strokeWidth: 3, strokeOpacity: 0.7, dash: '2 22');
        }

        $start = $points[0];
        $svg .= Svg::circle($start[0], $start[1], 16, fill: Svg::CREAM, stroke: Svg::INK, strokeWidth: 5);
        $svg .= $this->fieldLabel('START', $start[0], $start[1] + 8, $fx, $fw);

        if ($facts->form === RunForm::Race) {
            $finish = $points[count($points) - 1];
            for ($i = 0; $i < 8; $i++) {
                $svg .= Svg::rect(
                    $finish[0] - 18 + ($i % 2) * 18,
                    $finish[1] - 36 + intdiv($i, 2) * 18,
                    18,
                    18,
                    fill: ($i + intdiv($i, 2)) % 2 === 1 ? Svg::INK : Svg::CREAM,
                    stroke: Svg::INK,
                    strokeWidth: 1.5,
                );
            }
            $svg .= $this->fieldLabel('FINISH', $finish[0], $finish[1] + 64, $fx, $fw);
        }

        $ticks = match ($facts->form) {
            RunForm::Long => 3,
            RunForm::Race => 4,
            default => 0,
        };
        foreach (Svg::along($points, $ticks) as $i => [$mx, $my]) {
            $km = (int) round(($facts->distanceKm / ($ticks + 1)) * ($i + 1));
            $svg .= Svg::circle($mx, $my, 15, fill: Svg::CREAM, stroke: $traceColour, strokeWidth: 4);
            $svg .= Svg::text((string) $km, $mx, $my + 7, 18, Svg::INK, weight: 700, anchor: 'middle');
        }

        return $svg;
    }

    /** A label set away from the field edge it is nearest, so it never runs off. */
    private function fieldLabel(string $value, float $x, float $y, float $fx, float $fw): string
    {
        $leftward = $x > $fx + $fw * 0.6;

        return Svg::text(
            $value,
            $x + ($leftward ? -34 : 34),
            $y,
            20,
            Svg::INK_2,
            anchor: $leftward ? 'end' : 'start',
            tracking: 3
        );
    }

    /** Nothing to survey: a blank grid under a diagonal hatch. */
    private function unsurveyed(CardFacts $facts, float $fx, float $fy, float $fw, float $fh, bool $story): string
    {
        $svg = '';
        for ($i = $fx; $i < $fx + $fw; $i += 40) {
            $svg .= Svg::line($i, $fy, $i, $fy + $fh, Svg::LINE, width: 1, opacity: 0.7);
        }
        for ($i = $fy; $i < $fy + $fh; $i += 40) {
            $svg .= Svg::line($fx, $i, $fx + $fw, $i, Svg::LINE, width: 1, opacity: 0.7);
        }
        for ($i = -$fh; $i < $fw; $i += 54) {
            $svg .= Svg::line($fx + $i, $fy + $fh, $fx + $i + $fh, $fy, Svg::LINE_STRONG, width: 3, opacity: 0.5);
        }

        $cx = $fx + $fw / 2;
        $cy = $fy + $fh / 2;
        $svg .= Svg::text(
            'UNSURVEYED',
            $cx,
            $cy - 18,
            $story ? 68 : 54,
            Svg::INK_2,
            weight: 700,
            anchor: 'middle',
            tracking: 12
        );
        $svg .= Svg::line($fx + 60, $cy + 60, $fx + $fw - 60, $cy + 60, Svg::INK, width: 12, cap: 'butt');

        return $svg.Svg::text(
            'NO TRACE · '.$facts->km.' KM',
            $cx,
            $cy + 110,
            $story ? 26 : 22,
            Svg::INK_3,
            anchor: 'middle',
            tracking: 5
        );
    }

    private function scaleBar(float $x, float $y, int $km): string
    {
        $segment = 54.0;
        $svg = '';
        for ($i = 0; $i < 4; $i++) {
            $svg .= Svg::rect(
                $x + $i * $segment,
                $y,
                $segment,
                12,
                fill: $i % 2 === 1 ? Svg::CREAM : Svg::INK,
                stroke: Svg::INK,
                strokeWidth: 2
            );
        }

        return $svg
            .Svg::text('0', $x, $y + 34, 19, Svg::INK_2, anchor: 'middle')
            .Svg::text($km.' KM', $x + $segment * 4, $y + 34, 19, Svg::INK_2, anchor: 'middle');
    }

    private function northArrow(float $x, float $y): string
    {
        $d = sprintf(
            'M%s,%s L%s,%s L%s,%s L%s,%s Z',
            Svg::num($x),
            Svg::num($y - 34),
            Svg::num($x + 15),
            Svg::num($y + 16),
            Svg::num($x),
            Svg::num($y + 4),
            Svg::num($x - 15),
            Svg::num($y + 16)
        );

        return Svg::path($d, fill: Svg::INK)
            .Svg::text('N', $x, $y + 44, 22, Svg::INK, weight: 700, anchor: 'middle');
    }

    private function surveyStamp(CardFacts $facts, float $x, float $y, string $rarityInk): string
    {
        $isPr = $facts->form === RunForm::Pr;

        // Backed in paper: the stamp lands in a fixed corner of the field, and
        // a real trace can run straight under it.
        return Svg::circle($x, $y, 92, fill: Svg::CREAM, opacity: 0.92)
            .Svg::circle($x, $y, 92, stroke: $rarityInk, strokeWidth: 5, strokeOpacity: 0.85)
            .Svg::circle($x, $y, 78, stroke: $rarityInk, strokeWidth: 2, strokeOpacity: 0.85)
            .Svg::text(
                $isPr ? 'NEW BEST' : 'CERTIFIED',
                $x,
                $y - 10,
                22,
                $rarityInk,
                weight: 700,
                anchor: 'middle',
                tracking: 3,
                opacity: 0.9
            )
            .Svg::text(
                $isPr ? $facts->km.' KM' : mb_strtoupper($facts->rarity->label()),
                $x,
                $y + 30,
                24,
                $rarityInk,
                weight: 700,
                anchor: 'middle',
                opacity: 0.9
            );
    }

    private function titleBlock(CardFacts $facts, float $x, float $y, float $width, bool $story): string
    {
        $svg = $this->titleRow($x, $y, $width, [
            ['DISTANCE', $facts->km.' KM'],
            [$facts->form === RunForm::Race ? 'FINISH' : 'TIME', $facts->time],
            ['PACE', $facts->pace.'/K'],
        ], big: true);
        $y += $story ? 128 : 118;

        $second = [['DATE', $facts->dateShort], ['START', $facts->clock]];
        if ($facts->heartRate !== null) {
            $second[] = ['AVG HR', $facts->heartRate];
        }
        if ($facts->elevation !== null) {
            $second[] = ['ELEV', $facts->elevation.' M'];
        }
        $svg .= $this->titleRow($x, $y, $width, $second, big: false);
        $y += $story ? 96 : 90;

        if (! $story) {
            return $svg;
        }

        if ($facts->form === RunForm::Race && $facts->splits !== []) {
            $svg .= Svg::line($x, $y, $x + $width, $y, Svg::INK, width: 2);
            $svg .= Svg::text('SPLITS', $x + 18, $y + 30, 19, Svg::INK_3, tracking: 4);
            $cellWidth = ($width - 36) / count($facts->splits);
            foreach ($facts->splits as $i => [$label, $value]) {
                $sx = $x + 18 + $i * $cellWidth;
                $svg .= Svg::text($label, $sx, $y + 62, 22, Svg::INK_3);
                $svg .= Svg::text($value, $sx, $y + 92, 30, Svg::INK, weight: 700);
            }
        }

        if ($facts->form === RunForm::Long && $facts->paceProfile !== []) {
            $svg .= $this->paceProfile($facts, $x, $y, $width);
        }

        return $svg;
    }

    /**
     * The run's own shape, km by km — the survey's record of effort rather than
     * an invented terrain profile, since no elevation series is stored.
     */
    private function paceProfile(CardFacts $facts, float $x, float $y, float $width): string
    {
        $svg = Svg::line($x, $y, $x + $width, $y, Svg::INK, width: 2);
        $svg .= Svg::text('PACE PROFILE', $x + 18, $y + 28, 19, Svg::INK_3, tracking: 4);

        $gx = $x + 18;
        $gy = $y + 96;
        $gw = $width - 36;
        $steps = count($facts->paceProfile);
        $d = sprintf('M%s,%s', Svg::num($gx), Svg::num($gy));
        foreach ($facts->paceProfile as $i => $value) {
            $px = $gx + ($steps < 2 ? 0 : ($i / ($steps - 1)) * $gw);
            $d .= sprintf(' L%s,%s', Svg::num($px), Svg::num($gy - 12 - $value * 42));
        }
        $d .= sprintf(' L%s,%s Z', Svg::num($gx + $gw), Svg::num($gy));

        return $svg.Svg::path($d, fill: Svg::LEAF, stroke: Svg::LEAF_INK, strokeWidth: 2, opacity: 0.22);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items
     */
    private function titleRow(float $x, float $y, float $width, array $items, bool $big): string
    {
        $svg = Svg::line($x, $y, $x + $width, $y, Svg::INK, width: 2);
        $cellWidth = $width / count($items);

        foreach ($items as $i => [$label, $value]) {
            $cx = $x + $i * $cellWidth;
            if ($i > 0) {
                $svg .= Svg::line($cx, $y, $cx, $y + ($big ? 118 : 86), Svg::LINE, width: 2);
            }
            $svg .= Svg::text($label, $cx + 18, $y + 30, 19, Svg::INK_3, tracking: 4);
            $svg .= Svg::text(
                $value,
                $cx + 18,
                $y + ($big ? 100 : 70),
                $big ? 56 : 30,
                Svg::INK,
                weight: $big ? 700 : 500
            );
        }

        return $svg;
    }

    private function legend(CardFacts $facts, float $x, float $width, float $y, bool $story, string $rarityInk): string
    {
        $size = $story ? 22.0 : 19.0;
        $textX = $x + ($story ? 200 : 180);

        $svg = Svg::wordmark($x, $y, $story ? 48 : 42, Svg::HORIZON_INK);
        $svg .= Svg::text(Svg::clip($facts->placeShort, 22), $textX, $y - 22, $size, Svg::INK_2, tracking: 2);
        $svg .= Svg::text(mb_strtoupper(Svg::clip((string) $facts->weather, 22)), $textX, $y + 6, $size, Svg::INK_3, tracking: 2);

        $badges = Svg::joinWithin(array_map(mb_strtoupper(...), $facts->badges), '  ·  ', 26);
        if ($badges !== '') {
            $svg .= Svg::text(
                $badges,
                $x + $width,
                $y - 22,
                $size,
                $rarityInk,
                weight: 600,
                anchor: 'end',
                tracking: 2
            );
        }

        return $svg.Svg::text($facts->kind, $x + $width, $y + 6, $size, Svg::INK_3, anchor: 'end', tracking: 4);
    }
}
