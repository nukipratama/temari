<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\CalendarBuilder;
use App\Services\Run\LifetimeStats;
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

        return Inertia::render('Riwayat/Kalender', [
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $this->formatMonthLabel($monthStart),
            'prevMonth' => $monthStart->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $monthStart->copy()->addMonthNoOverflow()->format('Y-m'),
            'todayMonth' => Carbon::today()->format('Y-m'),
            'cells' => $this->calendarBuilder->buildCells($user, $gridStart, $gridEnd, $monthStart, $monthEnd),
            'lifetime' => $this->lifetimeStats->forUser($user),
            'todayQuote' => $this->todayQuote($user),
        ]);
    }

    private function todayQuote(User $user): ?string
    {
        return StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereHas('activity', fn ($q) => $q
                ->where('user_id', $user->id)
                ->whereHas('detail', fn ($q) => $q->whereDate('start_date_local', Carbon::today())))
            ->orderByDesc('id')
            ->value('speech');
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
