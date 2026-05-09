<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class StravaAuthController extends Controller
{
    private const array SCOPES = ['read', 'activity:read_all'];

    public function redirect(): SymfonyRedirectResponse
    {
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

        $user = $this->upsertUser($stravaUser);

        Auth::login($user, remember: true);

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

    private function upsertUser(SocialiteUser $stravaUser): User
    {
        $athleteId = $stravaUser->getId();
        $userAttributes = [
            'name' => $stravaUser->getName() ?: 'Strava Athlete',
            'email' => $stravaUser->getEmail(),
            'avatar_url' => $stravaUser->getAvatar(),
        ];
        $connectionAttributes = [
            'access_token' => $stravaUser->token,
            'refresh_token' => $stravaUser->refreshToken,
            'token_expires_at' => Carbon::now()->addSeconds($stravaUser->expiresIn),
            'scopes' => implode(',', self::SCOPES),
        ];

        $connection = StravaConnection::where('strava_athlete_id', $athleteId)->first();

        if ($connection !== null) {
            $connection->user->fill($userAttributes)->save();
            $connection->fill($connectionAttributes)->save();

            return $connection->user;
        }

        $user = User::create($userAttributes);
        $user->stravaConnection()->create([
            'strava_athlete_id' => $athleteId,
            ...$connectionAttributes,
        ]);

        return $user;
    }
}
