<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Run\Metrics\SessionIntent;

enum Effort: string
{
    case Easy = 'easy';
    case Steady = 'steady';
    case Hard = 'hard';
    case Rest = 'rest';
    case Unknown = 'unknown';

    /** An untagged easy run at or past this length is long-run work. */
    public const int LONG_RUN_MIN_SECONDS = 90 * 60;

    public static function fromSessionType(SessionType $type): self
    {
        return match ($type) {
            SessionType::Easy => self::Easy,
            SessionType::Long, SessionType::Tempo => self::Steady,
            SessionType::Interval, SessionType::Race => self::Hard,
            SessionType::Rest => self::Rest,
        };
    }

    public static function fromIntent(string $intent, int $elapsedSeconds): self
    {
        return match ($intent) {
            SessionIntent::RACE, SessionIntent::WORKOUT => self::Hard,
            SessionIntent::LONG_RUN => self::Steady,
            SessionIntent::EASY => $elapsedSeconds >= self::LONG_RUN_MIN_SECONDS ? self::Steady : self::Easy,
            default => self::Unknown,
        };
    }
}
