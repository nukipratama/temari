<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Telegram\Concerns\PushesAnalysisToTelegram;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual "Kirim ke Telegram" for a month's recap. The monthly recap's subject IS
 * the user (subject_id = user id, discriminator = 'Y-m'), so scoping the lookup
 * to the caller's own id is the authorization: another user's month simply finds
 * no row. Forces (force: true) like the other manual pushes, so it bypasses the
 * notify_monthly_recap toggle and the once-only guard and can be re-sent.
 */
class SendMonthlyRecapNotificationController extends Controller
{
    use PushesAnalysisToTelegram;

    public function __invoke(Request $request, string $month): RedirectResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);

        /** @var User $user */
        $user = $request->user();

        $analysis = Analysis::query()
            ->forSubject(
                AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                $user->id,
                AnalysisType::MonthlyRecap,
                $month,
            )
            ->first();

        return $this->pushOrDeferred(
            $user,
            $analysis,
            'Rekapnya belum siap, coba lagi sebentar ya.',
            'Aku kirim rekap bulanan ini ke Telegram kamu ya.',
        );
    }
}
