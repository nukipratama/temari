<?php

declare(strict_types=1);

use App\Events\TrendSnapshotsSettled;

it('carries the settled user id', function (): void {
    expect(new TrendSnapshotsSettled(42)->userId)->toBe(42);
});
