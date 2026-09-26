<?php

declare(strict_types=1);

use App\Enums\StravaGrantReleaseStatus;
use App\Services\Strava\StravaGrantReleaseResult;

it('counts accepted and already-invalid grants as freed slots', function (StravaGrantReleaseStatus $status, bool $freed): void {
    expect(new StravaGrantReleaseResult($status)->freedSlot())->toBe($freed);
})->with([
    'released' => [StravaGrantReleaseStatus::Released, true],
    'rejected' => [StravaGrantReleaseStatus::Rejected, true],
    'failed' => [StravaGrantReleaseStatus::Failed, false],
    'stale' => [StravaGrantReleaseStatus::Stale, false],
]);
