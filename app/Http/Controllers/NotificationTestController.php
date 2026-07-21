<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\TestNotification;
use App\Services\Notifications\ChannelRouter;
use App\Support\Cooldown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends the channel-agnostic {@see TestNotification} to whatever channels the
 * signed-in user has wired — Telegram if connected, web push if subscribed. One
 * "Kirim tes" for every channel; the notification's via() picks the destinations.
 *
 * Cooled per user for a short window. The route's `throttle:6,1` still bounds
 * abuse, but six silent sends a minute is a lot of buzzing for a mis-tap, and a
 * throttle rejection is a 429 the UI cannot render a countdown from.
 */
class NotificationTestController extends Controller
{
    public function __invoke(Request $request, ChannelRouter $router): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $router->canReach($user)) {
            // Covers a muted channel too, not just an unwired one: the button
            // should not claim to have sent anything that will never arrive.
            return back()->with('info', 'Nyalakan notifikasi dulu ya.');
        }

        // attempt() rather than isActive()+start(): the pair leaves a gap where
        // two concurrent taps both see it inactive and both send.
        $cooldown = new Cooldown(Cooldown::testNotificationKey($user->id), Cooldown::TEST_WINDOW_SECONDS);
        if (! $cooldown->attempt()) {
            return back()->with('info', 'Barusan udah dikirim. Tunggu sebentar ya.');
        }

        $user->notify(new TestNotification());

        return back()->with('success', 'Aku kirim notifikasi tes ya.');
    }
}
