<?php

declare(strict_types=1);

/**
 * Demo-mode toggles. When `login_enabled` is true, the login page renders
 * a "Try the demo" button below "Connect with Strava" that signs in the
 * seeded demo user. The signed-URL dev route + the demo:login command
 * still work independently so CLI workflows aren't disturbed.
 *
 * Set `DEMO_LOGIN_ENABLED=true` in local .env after running
 * `php artisan demo:seed --fresh`. Default is off so production never
 * exposes the button.
 */
return [
    'login_enabled' => (bool) env('DEMO_LOGIN_ENABLED', false),
];
