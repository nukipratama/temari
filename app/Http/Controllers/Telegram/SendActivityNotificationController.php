<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\Telegram\SendTelegramNotificationJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual "Kirim ke Telegram" on a run's detail page: pushes that run's post-run
 * narration to the owner's Telegram on demand. A manual override -- it ignores
 * the notify_post_run toggle and the once-only delivery guard (force: true), so
 * it can be re-sent, but still requires a Done narration and a connection (the
 * job enforces the connection / demo guards).
 */
class SendActivityNotificationController extends Controller
{
    public function __invoke(Request $request, Activity $activity): RedirectResponse
    {
        abort_unless($activity->user_id === $request->user()?->id, 404);

        $analysis = Analysis::query()
            ->forSubject(Activity::class, $activity->id, AnalysisType::PostRunSpeech)
            ->first();

        if ($analysis === null || $analysis->status !== AnalysisStatus::Done) {
            return back()->with('info', 'Ceritanya belum siap, coba lagi sebentar ya.');
        }

        SendTelegramNotificationJob::dispatch($analysis->id, force: true);

        return back()->with('success', 'Aku kirim cerita lari ini ke Telegram kamu ya.');
    }
}
