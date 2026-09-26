<?php

declare(strict_types=1);

namespace App\Enums;

enum StravaGrantEventType: string
{
    case Granted = 'granted';
    case Reconnected = 'reconnected';
    case RefusedInMaintenance = 'refused_in_maintenance';
    case Released = 'released';
    case ReleaseFailed = 'release_failed';
    case Rejected = 'rejected';
    case ForceReleased = 'force_released';

    /** @return list<string> */
    public static function openValues(): array
    {
        return [
            self::Granted->value,
            self::Reconnected->value,
            self::RefusedInMaintenance->value,
            self::ReleaseFailed->value,
        ];
    }

    /** @return list<string> */
    public static function releaseAttemptValues(): array
    {
        return [
            self::Released->value,
            self::ReleaseFailed->value,
            self::Rejected->value,
            self::ForceReleased->value,
        ];
    }
}
