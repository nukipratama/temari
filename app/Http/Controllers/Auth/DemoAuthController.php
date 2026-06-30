<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LocalRedirectPath;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoAuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        abort_unless((bool) config('demo.login_enabled'), 404);

        $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->first();
        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'demo' => 'Demo user belum di-seed. Jalankan `php artisan demo:seed` dulu.',
            ]);
        }

        $from = LocalRedirectPath::sanitize($request->input('from'));
        if ($from !== null) {
            redirect()->setIntendedUrl(url($from));
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }
}
