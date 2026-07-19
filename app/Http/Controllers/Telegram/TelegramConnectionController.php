<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Disconnect the signed-in user's Telegram connection. The link itself happens
 * through the bot (see TelegramWebhookController); the per-type notification
 * opt-ins are channel-neutral now and live in {@see \App\Http\Controllers\NotificationPreferenceController}.
 */
class TelegramConnectionController extends Controller
{
    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->telegramConnection?->markRevoked();

        return back();
    }
}
