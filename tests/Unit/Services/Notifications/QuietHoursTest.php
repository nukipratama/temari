<?php

declare(strict_types=1);

use App\Services\Notifications\QuietHours;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('holds from 22:00 up to but not including 04:00', function (string $at, bool $quiet): void {
    expect(QuietHours::contains(Carbon::parse($at)))->toBe($quiet);
})->with([
    '21:59:59' => ['2026-10-05 21:59:59', false],
    '22:00:00' => ['2026-10-05 22:00:00', true],
    'midnight' => ['2026-10-06 00:00:00', true],
    '03:59:59' => ['2026-10-06 03:59:59', true],
    '04:00:00' => ['2026-10-06 04:00:00', false],
]);

it('is in effect only inside the window and while the hold is switched on', function (): void {
    config(['notifications.hold_during_quiet_hours' => true]);
    Carbon::setTestNow('2026-10-05 23:00:00');
    expect(QuietHours::inEffect())->toBeTrue();

    Carbon::setTestNow('2026-10-05 12:00:00');
    expect(QuietHours::inEffect())->toBeFalse();

    Carbon::setTestNow('2026-10-05 23:00:00');
    config(['notifications.hold_during_quiet_hours' => false]);
    expect(QuietHours::inEffect())->toBeFalse();
});
