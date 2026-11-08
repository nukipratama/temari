<?php

declare(strict_types=1);

use App\Events\EarlyPassStraddled;
use App\Models\AI\Analysis;

it('carries the straddling row', function (): void {
    $row = new Analysis();

    expect(new EarlyPassStraddled($row)->analysis)->toBe($row);
});
