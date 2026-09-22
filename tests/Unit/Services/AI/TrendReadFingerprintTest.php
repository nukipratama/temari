<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AI\TrendReadFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('returns unchanged when no completed trend read exists', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
    $user = User::factory()->create();

    expect(app(TrendReadFingerprint::class)->changed($user, '7d'))->toBeFalse();

    Carbon::setTestNow();
});
