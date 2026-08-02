<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationPreferenceRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Update the signed-in user's notification preferences, on both axes: the
 * channel-neutral master switch over everything Temari sends, and the
 * per-channel mutes. A missing row means all-on, so the first write creates it.
 */
class NotificationPreferenceController extends Controller
{
    public function __invoke(UpdateNotificationPreferenceRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->notificationPreference()->updateOrCreate([], $request->validated());

        return back();
    }
}
