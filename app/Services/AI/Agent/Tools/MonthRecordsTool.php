<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\User;
use Override;

/**
 * The personal records the calendar month's runs set, bounded to that month (`Y-m`).
 */
final class MonthRecordsTool extends PeriodRecordsTool
{
    public function __construct(User $user, string $month)
    {
        [$from, $to] = MonthRunsTool::bounds($month);

        parent::__construct($user, $from, $to);
    }

    public function name(): string
    {
        return 'get_month_records';
    }

    #[Override]
    protected function period(): string
    {
        return 'month';
    }
}
