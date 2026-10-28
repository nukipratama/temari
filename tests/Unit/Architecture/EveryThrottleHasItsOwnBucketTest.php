<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * A bare `throttle:N,M` keys its counter by user (or IP) alone, so every route
 * using one shares a single bucket whatever its own limit: a few plan views
 * once spent the allowance a test notification then needed.
 */
it('gives every numeric throttle in the routes a named bucket', function (): void {
    preg_match_all("/throttle:\\d+,\\d+(?=['\"])/", (string) File::get(base_path('routes/web.php')), $bare);

    expect($bare[0])->toBe([], 'routes/web.php has a throttle with no bucket name; add one, e.g. throttle:6,1,push-seen');
})->group('structure');
