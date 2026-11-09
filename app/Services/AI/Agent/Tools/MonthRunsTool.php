<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use Illuminate\Support\Carbon;
use Override;

/**
 * Every run of the calendar month a recap is about, bounded to that month (`Y-m`).
 */
final class MonthRunsTool extends PeriodRunsTool
{
    public function __construct(User $user, string $month, VerdictNarrator $verdicts)
    {
        [$from, $to] = self::bounds($month);

        parent::__construct($user, $from, $to, $verdicts);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function bounds(string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month)?->startOfMonth() ?? Carbon::now()->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    public function name(): string
    {
        return 'get_month_runs';
    }

    #[Override]
    protected function period(): string
    {
        return 'month';
    }

    #[Override]
    protected function span(): string
    {
        return 'first day to last';
    }
}
