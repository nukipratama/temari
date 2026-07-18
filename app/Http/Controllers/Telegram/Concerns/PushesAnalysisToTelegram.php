<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram\Concerns;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Services\AI\AnalysisStatus;
use App\Support\Cooldown;
use Illuminate\Http\RedirectResponse;

/**
 * Shared body of every manual "Kirim ke Telegram" controller: force-notify the
 * push (force: true, so it bypasses the notify_* toggle and the once-only guard,
 * and reaches every wired channel) when the analysis is Done, otherwise flash
 * that it isn't ready yet. A per-send {@see Cooldown} blocks re-firing the same
 * push within the window.
 */
trait PushesAnalysisToTelegram
{
    private function pushOrDeferred(User $user, ?Analysis $analysis, string $notReadyMessage, string $sentMessage): RedirectResponse
    {
        if ($analysis === null || $analysis->status !== AnalysisStatus::Done) {
            return back()->with('info', $notReadyMessage);
        }

        if (! new Cooldown(Cooldown::telegramKey($analysis->id))->attempt()) {
            return back()->with('info', 'Baru saja dikirim ke Telegram. Tunggu sebentar sebelum kirim ulang.');
        }

        $user->notify(new AnalysisReadyNotification($analysis, force: true));

        return back()->with('success', $sentMessage);
    }
}
