<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares durable plan recalibration progress', function (): void {
    $started = Carbon::parse('2026-09-22 04:00:00');
    $user = User::factory()->create();
    $user->forceFill(['plan_recalibration_started_at' => $started])->saveQuietly();

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('planRecalibration.pending', true)
            ->where('planRecalibration.started_at', $started->toIso8601String())
            ->where('planRecalibration.completed_at', null));
});
