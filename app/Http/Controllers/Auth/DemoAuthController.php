<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * One-tap sign-in as the seeded demo user. The login page renders a button
 * that posts here when `config('demo.login_enabled')` is true; production
 * deployments leave the flag off so the endpoint 404s.
 */
class DemoAuthController extends Controller
{
    public function login(): RedirectResponse
    {
        abort_unless((bool) config('demo.login_enabled'), 404);

        $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->first();
        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'demo' => 'Demo user belum di-seed. Jalankan `php artisan demo:seed --fresh` dulu.',
            ]);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
