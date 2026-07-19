<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Telegram\Concerns\PushesAnalysisToTelegram;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual "Kirim ke Telegram" on a run's detail page: pushes that run's post-run
 * narration to the owner's Telegram on demand. A manual override -- it ignores
 * the post-run opt-in and the once-only delivery guard (force: true), so
 * it can be re-sent, but still requires a Done narration and a connection (the
 * job enforces the connection / demo guards).
 */
class SendActivityNotificationController extends Controller
{
    use PushesAnalysisToTelegram;

    public function __invoke(Request $request, Activity $activity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

        $analysis = Analysis::query()
            ->forSubject(Activity::class, $activity->id, AnalysisType::PostRunSpeech)
            ->first();

        return $this->pushOrDeferred(
            $user,
            $analysis,
            'Ceritanya belum siap, coba lagi sebentar ya.',
            'Aku kirim cerita lari ini ke Telegram kamu ya.',
        );
    }
}
