<?php

declare(strict_types=1);

namespace App\Enums;

enum RaceAmbitionState: string
{
    case OnTrack = 'on_track';
    case Ambitious = 'ambitious';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}
