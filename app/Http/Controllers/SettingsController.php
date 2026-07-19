<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\TelegramLinkToken;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __invoke(Request $request, TelegramLinkToken $telegramLinkToken): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Pengaturan/Index', [
            'telegram' => $this->resolveTelegram($user, $telegramLinkToken),
            'notificationPrefs' => $this->resolveNotificationPrefs($user),
        ]);
    }

    /**
     * @return array{connected: bool, username: string|null, connect_url: string|null}
     */
    private function resolveTelegram(User $user, TelegramLinkToken $linkToken): array
    {
        $botUsername = (string) config('services.telegram.bot_username');
        // A fresh, signed deep-link token per render (60 min TTL). Null when the
        // bot username isn't configured, so the UI hides the connect button.
        $connectUrl = $botUsername !== ''
            ? "https://t.me/{$botUsername}?start=" . $linkToken->mint($user->id)
            : null;

        $connection = $user->telegramConnection;
        if ($connection === null) {
            return ['connected' => false, 'username' => null, 'connect_url' => $connectUrl];
        }

        $connected = ! $connection->isRevoked();

        return [
            'connected' => $connected,
            'username' => $connected ? $connection->username : null,
            'connect_url' => $connectUrl,
        ];
    }

    /**
     * The channel-neutral per-type opt-ins (govern Telegram + web push alike). A
     * missing preference row means all-on, so an untouched account defaults to true.
     *
     * @return array{post_run: bool, weekly_recap: bool, monthly_recap: bool}
     */
    private function resolveNotificationPrefs(User $user): array
    {
        $preference = $user->notificationPreference;
        if ($preference === null) {
            return ['post_run' => true, 'weekly_recap' => true, 'monthly_recap' => true];
        }

        return [
            'post_run' => $preference->post_run,
            'weekly_recap' => $preference->weekly_recap,
            'monthly_recap' => $preference->monthly_recap,
        ];
    }
}
