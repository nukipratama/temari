<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Carbon\CarbonInterface;

/**
 * The fixed 22:00-04:00 window, on the app clock, in which every notification
 * except the manual test is held and then released together at 04:00.
 */
final class QuietHours
{
    private const int STARTS_AT_HOUR = 22;

    private const int ENDS_AT_HOUR = 4;

    public static function inEffect(): bool
    {
        return (bool) config('notifications.hold_during_quiet_hours') && self::contains(now());
    }

    public static function contains(CarbonInterface $at): bool
    {
        return $at->hour >= self::STARTS_AT_HOUR || $at->hour < self::ENDS_AT_HOUR;
    }
}
