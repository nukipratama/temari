<?php

declare(strict_types=1);

namespace App\Services\Weather;

/**
 * The US National Weather Service heat index: the Rothfusz regression with its
 * low- and high-humidity adjustments (wpc.ncep.noaa.gov/html/heatindex_equation.shtml).
 */
final class HeatIndex
{
    /** Where the NWS "Extreme Caution" band starts. */
    public const float HOT_RUN_FAHRENHEIT = 90.0;

    public static function fahrenheit(float $tempC, float $humidityPct): float
    {
        $t = $tempC * 9 / 5 + 32;
        $rh = $humidityPct;

        $simple = 0.5 * ($t + 61.0 + ($t - 68.0) * 1.2 + $rh * 0.094);
        if (($simple + $t) / 2 < 80) {
            return $simple;
        }

        $index = -42.379 + 2.04901523 * $t + 10.14333127 * $rh
            - 0.22475541 * $t * $rh - 0.00683783 * $t * $t - 0.05481717 * $rh * $rh
            + 0.00122874 * $t * $t * $rh + 0.00085282 * $t * $rh * $rh - 0.00000199 * $t * $t * $rh * $rh;

        if ($rh < 13 && $t >= 80 && $t <= 112) {
            return $index - ((13 - $rh) / 4) * sqrt((17 - abs($t - 95)) / 17);
        }

        if ($rh > 85 && $t >= 80 && $t <= 87) {
            return $index + (($rh - 85) / 10) * ((87 - $t) / 5);
        }

        return $index;
    }
}
