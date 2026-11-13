<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('floors a saved briefing send time to the quarter hour it already sent on', function (?int $saved, ?int $floored): void {
    $preference = NotificationPreference::factory()->create(['briefing_push_minute' => $saved]);

    $migration = require base_path('database/migrations/2026_11_13_000000_floor_briefing_push_minute_to_quarter_hour.php');
    $migration->up();

    expect($preference->refresh()->briefing_push_minute)->toBe($floored);
})->with([
    'off the quarter hour' => [5 * 60 + 10, 5 * 60],
    'on the quarter hour' => [5 * 60 + 45, 5 * 60 + 45],
    'automatic' => [null, null],
]);
