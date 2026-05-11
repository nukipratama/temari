<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * Tiny accessor helpers over the loosely-typed `stream_summary` JSON
 * shape that StreamAnalysis emits. Centralises the `is_array(... ?? null)`
 * guard so callers don't all reimplement it.
 */
final class StreamSummary
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, float|int>
     */
    public static function zonePct(array $summary): array
    {
        $pct = $summary['time_in_zone_pct'] ?? null;

        return is_array($pct) ? $pct : [];
    }

    /**
     * Z3 + Z4 + Z5 share of total time-in-zone — proxies "how hard was this run".
     * Defaults to 0 when zone data is missing.
     *
     * @param  array<string, mixed>  $summary
     */
    public static function hardZoneShare(array $summary): float
    {
        $zonePct = self::zonePct($summary);

        return (float) ($zonePct['Z3'] ?? 0)
            + (float) ($zonePct['Z4'] ?? 0)
            + (float) ($zonePct['Z5'] ?? 0);
    }
}
