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
        ]);
    }

    /**
     * @return array{connected: bool, username: string|null, connect_url: string|null, notify_post_run: bool, notify_weekly_recap: bool, notify_monthly_recap: bool, notify_daily_briefing: bool}
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
            return [
                'connected' => false,
                'username' => null,
                'connect_url' => $connectUrl,
                'notify_post_run' => true,
                'notify_weekly_recap' => true,
                'notify_monthly_recap' => true,
                'notify_daily_briefing' => false,
            ];
        }

        $connected = ! $connection->isRevoked();

        return [
            'connected' => $connected,
            'username' => $connected ? $connection->username : null,
            'connect_url' => $connectUrl,
            'notify_post_run' => $connection->notify_post_run,
            'notify_weekly_recap' => $connection->notify_weekly_recap,
            'notify_monthly_recap' => $connection->notify_monthly_recap,
            'notify_daily_briefing' => $connection->notify_daily_briefing,
        ];
    }
}
