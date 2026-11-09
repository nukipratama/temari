<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\WeeklySnapshot;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use Illuminate\Support\Carbon;
use Override;

/**
 * Every run of the week a recap is about, Monday to Sunday, bounded to the snapshot's own week.
 */
final class WeekRunsTool extends PeriodRunsTool
{
    public function __construct(WeeklySnapshot $snapshot, VerdictNarrator $verdicts)
    {
        [$from, $to] = self::bounds($snapshot);

        parent::__construct($snapshot->user, $from, $to, $verdicts);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function bounds(WeeklySnapshot $snapshot): array
    {
        return [$snapshot->week_ending->copy()->subDays(6)->startOfDay(), $snapshot->week_ending->copy()->endOfDay()];
    }

    public function name(): string
    {
        return 'get_week_runs';
    }

    #[Override]
    protected function period(): string
    {
        return 'week';
    }

    #[Override]
    protected function span(): string
    {
        return 'Monday to Sunday';
    }
}
