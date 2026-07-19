<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Update the signed-in user's channel-neutral per-type notification opt-ins
 * (post-run / weekly recap / monthly recap). The same toggles gate both Telegram
 * and web push; a missing row means all-on, so the first write creates it.
 */
class NotificationPreferenceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'post_run' => ['required', 'boolean'],
            'weekly_recap' => ['required', 'boolean'],
            'monthly_recap' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->notificationPreference()->updateOrCreate([], $validated);

        return back();
    }
}
