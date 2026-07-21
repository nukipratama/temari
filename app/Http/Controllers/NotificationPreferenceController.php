<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Update the signed-in user's notification preferences, on both axes: the
 * channel-neutral per-type opt-ins (post-run / weekly recap / monthly recap)
 * and the per-channel mutes. A missing row means all-on, so the first write
 * creates it.
 *
 * Every field is `required` because the client always sends the complete state.
 * That invariant matters now that the toggles live in two different groups on
 * the page: a partial write would silently leave `updateOrCreate` holding
 * whatever was there before, which reads as a toggle that did not stick.
 */
class NotificationPreferenceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'post_run' => ['required', 'boolean'],
            'weekly_recap' => ['required', 'boolean'],
            'monthly_recap' => ['required', 'boolean'],
            'telegram_enabled' => ['required', 'boolean'],
            'push_enabled' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->notificationPreference()->updateOrCreate([], $validated);

        return back();
    }
}
