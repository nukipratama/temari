<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Telegram\Concerns\PushesAnalysisToTelegram;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual "Kirim ke Telegram" for a week's recap: pushes that week's recap
 * narration to the owner's Telegram on demand. Like the activity push, it forces
 * (force: true), so it ignores the weekly-recap opt-in and the once-only
 * delivery guard and can be re-sent, but still requires a Done recap and a live
 * connection (the job enforces the connection / demo guards).
 */
class SendWeeklyRecapNotificationController extends Controller
{
    use PushesAnalysisToTelegram;

    public function __invoke(Request $request, WeeklySnapshot $snapshot): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($snapshot->user_id === $user->id, 404);

        $analysis = Analysis::query()
            ->forSubject(WeeklySnapshot::class, $snapshot->id, AnalysisType::WeeklyRecap)
            ->first();

        return $this->pushOrDeferred(
            $user,
            $analysis,
            'Rekapnya belum siap, coba lagi sebentar ya.',
            'Aku kirim rekap mingguan ini ke Telegram kamu ya.',
        );
    }
}
