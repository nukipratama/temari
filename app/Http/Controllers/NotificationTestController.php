<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends the channel-agnostic {@see TestNotification} to whatever channels the
 * signed-in user has wired — Telegram if connected, web push if subscribed. One
 * "Kirim tes" for every channel; the notification's via() picks the destinations.
 */
class NotificationTestController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $connection = $user->telegramConnection;
        $hasChannel = ($connection !== null && ! $connection->isRevoked())
            || $user->pushSubscriptions()->exists();

        if (! $hasChannel) {
            return back()->with('info', 'Nyalakan notifikasi dulu ya.');
        }

        $user->notify(new TestNotification());

        return back()->with('success', 'Aku kirim notifikasi tes ya.');
    }
}
