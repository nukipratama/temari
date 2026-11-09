<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\WeeklySnapshot;
use Override;

/**
 * The personal records the week's runs set, bounded to the snapshot's own week.
 */
final class WeekRecordsTool extends PeriodRecordsTool
{
    public function __construct(WeeklySnapshot $snapshot)
    {
        [$from, $to] = WeekRunsTool::bounds($snapshot);

        parent::__construct($snapshot->user, $from, $to);
    }

    public function name(): string
    {
        return 'get_week_records';
    }

    #[Override]
    protected function period(): string
    {
        return 'week';
    }
}
