<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\CalendarBuilder;
use App\Services\Run\LifetimeStats;
use App\Services\Run\PostRunNoteReader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /kalender — Google-Calendar-style single-month view. `?month=YYYY-MM`
 * selects the visible month (defaults to today's). The cell grid pads
 * leading days from the previous month + trailing days from the next so
 * the grid always renders complete weeks (Mon-Sun). Each cell carries
 * per-day distance / pace / HR / mood derived from the user's runs so
 * the frontend can render rich detail without a second query.
 */
class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarBuilder $calendarBuilder,
        private readonly LifetimeStats $lifetimeStats,
        private readonly PostRunNoteReader $noteReader,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $month = $this->resolveMonth($request->query('month'));
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $discriminator = $monthStart->format('Y-m');
        $recapRow = Analysis::query()
            ->forSubject(
                AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                $user->id,
                AnalysisType::MonthlyRecap,
                $discriminator,
            )
            ->first();

        $recapPayload = Analysis::toPayload(
            $recapRow,
            AnalysisType::MonthlyRecap,
            AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
            $user->id,
            $discriminator,
        );

        return Inertia::render('Riwayat/Kalender', [
            'month' => $discriminator,
            'monthLabel' => $this->formatMonthLabel($monthStart),
            'prevMonth' => $monthStart->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $monthStart->copy()->addMonthNoOverflow()->format('Y-m'),
            'todayMonth' => Carbon::today()->format('Y-m'),
            'cells' => $this->calendarBuilder->buildCells($user, $gridStart, $gridEnd, $monthStart, $monthEnd),
            'lifetime' => $this->lifetimeStats->forUser($user),
            'todayQuote' => $this->noteReader->speechForToday($user->id),
            'monthlyRecap' => [
                ...$recapPayload,
                'is_chain_head' => $discriminator === $this->latestNarratedMonthFor($user),
                'notification_retry_after_seconds' => Analysis::notificationCooldownRemaining($recapPayload),
            ],
        ]);
    }

    /**
     * The latest completed month (Y-m, strictly before the current month) the
     * user has a run in, mirroring RunController's weekly chain-head flag: only
     * the latest narrated month may regenerate, so re-narrating mid-history
     * can't desync later links. Null when the user has no closed-month runs.
     */
    private function latestNarratedMonthFor(User $user): ?string
    {
        $currentMonthStart = Carbon::today()->startOfMonth();

        $latestDate = ActivityDetail::query()
            ->forUser($user->id)
            ->whereNotNull('start_date_local')
            ->where('start_date_local', '<', $currentMonthStart)
            ->max('start_date_local');

        return $latestDate === null ? null : Carbon::parse($latestDate)->format('Y-m');
    }

    private function resolveMonth(mixed $raw): Carbon
    {
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            try {
                return Carbon::parse($raw.'-01')->startOfMonth();
            } catch (Throwable) {
                // fall through to today
            }
        }

        return Carbon::today()->startOfMonth();
    }

    private function formatMonthLabel(Carbon $month): string
    {
        $labels = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        return $labels[$month->month - 1].' '.$month->year;
    }
}
