<?php

declare(strict_types=1);

use App\Enums\PlanRegenerationReason;
use App\Events\PlanRegenerated;
use App\Models\User;
use Illuminate\Support\Carbon;

it('carries the regenerated user, the day and why', function (): void {
    $user = new User();
    $today = Carbon::parse('2026-08-17');

    $event = new PlanRegenerated($user, $today, PlanRegenerationReason::Settings);

    expect($event->user)->toBe($user)
        ->and($event->today)->toBe($today)
        ->and($event->reason)->toBe(PlanRegenerationReason::Settings);
});
