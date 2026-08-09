<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateHrZonesRequest;
use App\Jobs\Strava\SyncZonesJob;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunnerZonesController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = RunnerProfile::query()->where('user_id', $user->id)->first();

        return Inertia::render('Pengaturan/ZonaHR', [
            'profile' => $user->hrProfile(),
            'hasCustomProfile' => $profile !== null,
            'source' => $profile !== null ? $profile->source : 'default',
            'stravaSyncedLabel' => $profile !== null ? $profile->strava_zones_synced_at?->format('j M Y, H:i') : null,
            'canSyncFromStrava' => $this->canSyncFromStrava($user),
        ]);
    }

    public function update(UpdateHrZonesRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<int, array{lo:int, hi:int}> $rawZones */
        $rawZones = array_values($request->validated('zones'));

        $hrZones = [];
        foreach (['Z1', 'Z2', 'Z3', 'Z4', 'Z5'] as $index => $key) {
            $hrZones[$key] = [
                'lo' => (int) $rawZones[$index]['lo'],
                'hi' => (int) $rawZones[$index]['hi'],
            ];
        }

        $user->runnerProfile()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'max_hr' => (int) $request->validated('max_hr'),
                'resting_hr' => (int) $request->validated('resting_hr'),
                'hr_zones' => $hrZones,
                'source' => 'manual',
            ],
        );

        return back()->with('success', 'Your HR zones are saved, and will apply to every run from here on.');
    }

    /**
     * Drop the caller's runner profile so their zones fall back to the standard
     * config-derived defaults. This is the only path back out of a `manual`
     * (or `strava`) source, since `index()` reports `default` when no row exists.
     */
    public function resetToDefault(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->runnerProfile()->delete();

        return back()->with('success', 'Your HR zones are back to the default.');
    }

    /**
     * Explicit user request to re-pull zones from Strava, overriding a `manual`
     * source. Guarded on the `profile:read_all` scope the zone endpoint needs.
     * Runs the sync inline (not queued) so the redirected page immediately
     * reflects the refreshed zones + `strava` source; the job swallows every
     * Strava error, so a slow/failed pull just leaves the profile untouched.
     */
    public function resyncFromStrava(Request $request, AppConfig $config): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->canSyncFromStrava($user), 403);

        if (! $config->boolean(AppConfigKey::StravaEnabled)) {
            return back()->with('info', 'Pulling from Strava is paused for now. It\'ll pick back up automatically.');
        }

        SyncZonesJob::dispatchSync($user->id, force: true);

        return back()->with('success', 'Your zones have been re-synced from Strava.');
    }

    private function canSyncFromStrava(User $user): bool
    {
        $connection = $user->stravaConnection;

        return $connection !== null && ! $connection->isRevoked() && $connection->hasZoneScope();
    }
}
