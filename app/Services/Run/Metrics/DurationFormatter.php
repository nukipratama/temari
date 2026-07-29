<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

final class DurationFormatter
{
    /** Seconds to mm:ss, or h:mm:ss past an hour. */
    public static function hms(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }
}
