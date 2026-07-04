<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\Telegram\SendTelegramTestJob;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manage the signed-in user's Telegram notification preferences and disconnect.
 * The link itself happens through the bot (see TelegramWebhookController); this
 * only touches an existing connection.
 */
class TelegramConnectionController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'notify_post_run' => ['required', 'boolean'],
            'notify_weekly_recap' => ['required', 'boolean'],
            'notify_monthly_recap' => ['required', 'boolean'],
            'notify_daily_briefing' => ['required', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->telegramConnection?->update($validated);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->telegramConnection?->markRevoked();

        return back();
    }

    public function test(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $connection = $user->telegramConnection;
        if ($connection === null || $connection->isRevoked()) {
            return back()->with('info', 'Sambungin Telegram dulu ya.');
        }

        SendTelegramTestJob::dispatch($user->id);

        return back()->with('success', 'Aku kirim notifikasi tes ke Telegram kamu ya.');
    }
}
