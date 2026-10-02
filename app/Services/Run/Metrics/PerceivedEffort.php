<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\ActivityDetail;
use Carbon\CarbonInterface;

/**
 * Session RPE for a run with no heart rate: the athlete's CR-10 score times
 * moving minutes, halved onto the scale of Edwards' 1–5 zone weights.
 */
final class PerceivedEffort
{
    public const int MIN = 1;

    public const int MAX = 10;

    public const int WINDOW_HOURS = 72;

    private const float CR10_TO_EDWARDS = 2.0;

    public static function load(ActivityDetail $detail): ?float
    {
        if ($detail->has_heartrate || $detail->perceived_effort === null) {
            return null;
        }
        if ($detail->moving_time === null || $detail->moving_time <= 0) {
            return null;
        }

        return $detail->perceived_effort * ($detail->moving_time / 60) / self::CR10_TO_EDWARDS;
    }

    public static function accepts(ActivityDetail $detail, CarbonInterface $now): bool
    {
        if ($detail->has_heartrate) {
            return false;
        }

        $start = $detail->start_date_utc?->copy()->shiftTimezone('UTC') ?? $detail->start_date_local;

        return $start !== null && $now->lt($start->copy()->addHours(self::WINDOW_HOURS));
    }

    /**
     * @return array{activity_id: int, score: int|null, name: string|null, start_date_local: string|null}|null
     */
    public static function prompt(ActivityDetail $detail, CarbonInterface $now): ?array
    {
        if (! self::accepts($detail, $now)) {
            return null;
        }

        return [
            'activity_id' => $detail->activity_id,
            'score' => $detail->perceived_effort,
            'name' => $detail->name,
            'start_date_local' => $detail->start_date_local?->format('Y-m-d\TH:i:s'),
        ];
    }
}
