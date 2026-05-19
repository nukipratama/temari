<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Shared predicate for the devtools gates (Horizon, Telescope, Pulse,
 * AI Usage). A user is considered an admin when their connected Strava
 * athlete id appears in `config('devtools.admin_strava_ids')`.
 */
class Devtools
{
    public static function isAdmin(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $athleteId = $user->stravaConnection?->strava_athlete_id;
        if ($athleteId === null) {
            return false;
        }

        /** @var array<int, int> $allowed */
        $allowed = config('devtools.admin_strava_ids', []);

        return in_array((int) $athleteId, $allowed, true);
    }
}
