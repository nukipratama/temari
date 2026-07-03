<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram\Concerns;

use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Support\Cooldown;
use Illuminate\Http\RedirectResponse;

/**
 * Shared body of every manual "Kirim ke Telegram" controller: force-dispatch the
 * push (force: true, so it bypasses the notify_* toggle and the once-only guard)
 * when the analysis is Done, otherwise flash that it isn't ready yet. A per-send
 * {@see Cooldown} blocks re-firing the same push within the window.
 */
trait PushesAnalysisToTelegram
{
    private function pushOrDeferred(?Analysis $analysis, string $notReadyMessage, string $sentMessage): RedirectResponse
    {
        if ($analysis === null || $analysis->status !== AnalysisStatus::Done) {
            return back()->with('info', $notReadyMessage);
        }

        $cooldown = new Cooldown(Cooldown::telegramKey($analysis->id));
        if ($cooldown->isActive()) {
            return back()->with('info', 'Baru saja dikirim ke Telegram. Tunggu sebentar sebelum kirim ulang.');
        }
        $cooldown->start();

        SendTelegramNotificationJob::dispatch($analysis->id, force: true);

        return back()->with('success', $sentMessage);
    }
}
