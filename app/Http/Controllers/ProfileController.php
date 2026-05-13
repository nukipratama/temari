<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $connection = $user->stravaConnection;

        $totalRuns = $user->activities()->whereNotNull('analyzed_at')->count();

        // Single SUM over detail rows joined to user's analyzed activities.
        // Avoid loading details into memory.
        $totalDistanceMeters = (float) ActivityDetail::query()
            ->whereHas(
                'activity',
                fn ($q) => $q->where('user_id', $user->id)->whereNotNull('analyzed_at'),
            )
            ->sum('distance');

        return Inertia::render('Profile', [
            'stats' => [
                'total_runs' => $totalRuns,
                'total_km' => round($totalDistanceMeters / 1000, 1),
                'member_since' => $user->created_at?->toIso8601String(),
            ],
            'strava' => $connection === null ? null : [
                'athlete_id' => $connection->strava_athlete_id,
                'scopes' => $connection->scopes,
                'token_expires_at' => $connection->token_expires_at->toIso8601String(),
            ],
        ]);
    }
}
