<?php

declare(strict_types=1);

use App\Services\Devtools\CostForecast;
use Illuminate\Support\Carbon;

it('projects the rest of the month at the trailing weekly rate', function (): void {
    $result = new CostForecast()->project(3.0, 7.0, Carbon::parse('2026-09-10'));

    // 30-day month, 20 days left, $1.00/day.
    expect($result['days_remaining'])->toBe(20)
        ->and($result['daily_rate'])->toBe(1.0)
        ->and($result['projected'])->toBe(23.0)
        ->and($result['month_to_date'])->toBe(3.0);
});

it('projects nothing beyond month-to-date on the last day of the month', function (): void {
    $result = new CostForecast()->project(5.0, 14.0, Carbon::parse('2026-09-30'));

    expect($result['days_remaining'])->toBe(0)
        ->and($result['projected'])->toBe(5.0);
});

it('defaults to today when no date is given', function (): void {
    Carbon::setTestNow('2026-09-10 08:00:00');

    expect(new CostForecast()->project(0.0, 0.0)['days_remaining'])->toBe(20);

    Carbon::setTestNow();
});
