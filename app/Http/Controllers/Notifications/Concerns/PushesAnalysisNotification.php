<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications\Concerns;

use App\Models\AI\Analysis;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Services\AI\AnalysisStatus;
use App\Support\Cooldown;
use Illuminate\Http\RedirectResponse;

/**
 * Shared body of every manual "Kirim notifikasi" controller: force-notify the
 * push (force: true, so it bypasses the per-type toggle and the once-only guard,
 * and reaches every wired channel — Telegram if connected, web push if
 * subscribed) when the analysis is Done, otherwise flash that it isn't ready yet.
 * A per-send {@see Cooldown} blocks re-firing the same push within the window.
 */
trait PushesAnalysisNotification
{
    private function pushOrDeferred(User $user, ?Analysis $analysis, string $notReadyMessage, string $sentMessage): RedirectResponse
    {
        if ($analysis === null || $analysis->status !== AnalysisStatus::Done) {
            return back()->with('info', $notReadyMessage);
        }

        if (! new Cooldown(Cooldown::notificationKey($analysis->id))->attempt()) {
            return back()->with('info', 'Notifikasinya baru saja dikirim. Tunggu sebentar sebelum kirim ulang.');
        }

        $user->notify(new AnalysisReadyNotification($analysis, force: true));

        return back()->with('success', $sentMessage);
    }
}
