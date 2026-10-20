<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * The one place this repo decides what a cardiac-decoupling figure means.
 *
 * {@see self::HIGH} sits above the 6-13% band an ordinary run lands in. The bands are descriptive:
 * no plan decision reads them.
 */
final class DecouplingBands
{
    /** Under this the HR/pace ratio barely moved: the aerobic base held. */
    public const float TIGHT = 2.0;

    /** A hard session that still finished with HR under control, rather than as a grind. */
    public const float CONTROLLED = 5.0;

    /** Past the top of the ordinary band: HR drifted well past pace, whatever the day asked for. */
    public const float HIGH = 12.0;

    /**
     * Which way a decoupling reading points, for a caller that must not invert a raw signed
     * percentage: 'up' when HR drifted up for the same pace, 'down' when it improved, 'flat' under
     * {@see self::TIGHT} either way.
     */
    public static function relationFor(float $pct): string
    {
        return match (true) {
            abs($pct) < self::TIGHT => 'flat',
            $pct > 0.0 => 'up',
            default => 'down',
        };
    }
}
