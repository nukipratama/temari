<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    /**
     * Delete the signed-in user's account. The User model's `deleting` hook
     * revokes the linked Strava connection, so this is the owner-facing way to
     * release a Strava-account binding. The shared demo account can't be deleted.
     */
    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->is_demo) {
            return back()->withErrors(['akun' => 'Akun demo gak bisa dihapus ya.']);
        }

        // Log out first: the session guard re-persists its authenticated user on
        // request termination, which would otherwise re-insert the row we delete.
        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('info', 'Akun kamu udah dihapus. Sambungan Strava juga udah dilepas. Makasih udah lari bareng Temari.');
    }
}
