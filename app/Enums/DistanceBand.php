<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for a planned session's named distance band. `Long` is always
 * the week's longest run (the weekly cornerstone); `Short`/`Medium` scale
 * proportionally under it. Deliberately not a stored km figure — actual km
 * is derived at render time from the athlete's current fitness, not frozen
 * at generation time (see {@see \App\Services\Run\Plan\DistanceBandKm}).
 */
enum DistanceBand: string
{
    case Short = 'short';
    case Medium = 'medium';
    case Long = 'long';
    case Rest = 'rest';
}
