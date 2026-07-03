<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram\Concerns;

use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use Illuminate\Http\RedirectResponse;

/**
 * Shared body of every manual "Kirim ke Telegram" controller: force-dispatch the
 * push (force: true, so it bypasses the notify_* toggle and the once-only guard)
 * when the analysis is Done, otherwise flash that it isn't ready yet.
 */
trait PushesAnalysisToTelegram
{
    private function pushOrDeferred(?Analysis $analysis, string $notReadyMessage, string $sentMessage): RedirectResponse
    {
        if ($analysis === null || $analysis->status !== AnalysisStatus::Done) {
            return back()->with('info', $notReadyMessage);
        }

        SendTelegramNotificationJob::dispatch($analysis->id, force: true);

        return back()->with('success', $sentMessage);
    }
}
