<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\Strava\SyncActivitiesJob;
use App\Models\StravaConnection;
use App\Models\User;
use App\Support\LocalRedirectPath;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class StravaAuthController extends Controller
{
    private const array SCOPES = ['read', 'activity:read_all'];

    public function redirect(Request $request): SymfonyRedirectResponse
    {
        $from = LocalRedirectPath::sanitize($request->query('from'));
        if ($from !== null) {
            redirect()->setIntendedUrl(url($from));
        }

        return $this->driver()->scopes(self::SCOPES)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors([
                'strava' => 'Strava authorization was cancelled or denied.',
            ]);
        }

        try {
            /** @var SocialiteUser $stravaUser */
            $stravaUser = $this->driver()->user();
        } catch (InvalidStateException|RequestException) {
            return redirect()->route('login')->withErrors([
                'strava' => 'We could not complete the Strava sign-in. Please try again.',
            ]);
        }

        // Strava returns the scopes the user actually granted (comma-separated)
        // in the `scope` query param. Store those, not what we requested.
        $grantedScopes = (string) $request->query('scope', '');

        [$user, $isFreshConnection] = $this->upsertUser($stravaUser, $grantedScopes);

        Auth::login($user, remember: true);

        // First-ever connect: pull the athlete's full history now so the
        // dashboard isn't empty until the hourly poll runs. Re-logins skip this
        // (the "Sync now" button covers a manual re-pull) — the orchestrator's
        // per-user lock + insertOrIgnore make a redundant dispatch harmless anyway.
        if ($isFreshConnection) {
            SyncActivitiesJob::dispatch($user->id);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();

        return redirect()->route('login');
    }

    private function driver(): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('strava');

        return $driver->redirectUrl(route('auth.strava.callback'));
    }

    /**
     * Upsert the athlete's user + connection. Returns the user alongside a flag
     * that is true only when the Strava connection was created for the first
     * time (so the caller can kick off a one-time history backfill).
     *
     * @return array{0: User, 1: bool}
     */
    private function upsertUser(SocialiteUser $stravaUser, string $grantedScopes = ''): array
    {
        $scopes = $grantedScopes !== '' ? $grantedScopes : implode(',', self::SCOPES);

        // Surface partial grants: if the athlete declined a scope we need, the
        // connection still saves but sync may silently return less data.
        $missing = array_diff(self::SCOPES, explode(',', $scopes));
        if ($missing !== []) {
            Log::warning('strava.scopes.partial', [
                'athlete_id' => $stravaUser->getId(),
                'granted' => $scopes,
                'missing' => array_values($missing),
            ]);
        }

        $userAttributes = [
            'name' => $stravaUser->getName() ?: 'Strava Athlete',
            'email' => $stravaUser->getEmail(),
            'avatar_url' => $stravaUser->getAvatar(),
        ];
        $connectionAttributes = [
            'access_token' => $stravaUser->token,
            'refresh_token' => $stravaUser->refreshToken,
            'token_expires_at' => Carbon::now()->addSeconds($stravaUser->expiresIn),
            'scopes' => $scopes,
        ];

        $connection = StravaConnection::where('strava_athlete_id', $stravaUser->getId())->first();

        if ($connection !== null) {
            $connection->user->fill($userAttributes)->save();
            $connection->fill($connectionAttributes)->save();

            return [$connection->user, false];
        }

        $user = User::create($userAttributes);
        $user->stravaConnection()->create([
            'strava_athlete_id' => $stravaUser->getId(),
            ...$connectionAttributes,
        ]);

        return [$user, true];
    }
}
