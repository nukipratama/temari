<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\User\UserEraser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    /**
     * Delete the signed-in user's account. The User model's `deleting` hook
     * revokes the linked Strava connection, so this is the owner-facing way to
     * release a Strava-account binding. The shared demo account can't be deleted.
     *
     * Removal itself lives in {@see UserEraser}, shared with `user:remove`, so
     * the button and the command cannot drift about what "owned data" means.
     */
    public function destroy(Request $request, UserEraser $eraser): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->is_demo) {
            return back()->withErrors(['akun' => 'The demo account can\'t be deleted.']);
        }

        // Log out first: the session guard re-persists its authenticated user on
        // request termination, which would otherwise re-insert the row we delete.
        Auth::logout();

        // Not $user->delete(): ai_analyses and push_subscriptions are
        // polymorphic with no user foreign key, so they never cascade.
        $eraser->erase($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('info', 'Your account has been deleted, and your Strava connection has been unlinked. Thanks for running with Temari.');
    }
}
