<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows the viewHorizon gate only for an admin user', function (): void {
    $admin = User::factory()->admin()->make();
    $plain = User::factory()->make();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($plain)->allows('viewHorizon'))->toBeFalse();
});

it('denies the viewHorizon gate for guests', function (): void {
    expect(Gate::allows('viewHorizon'))->toBeFalse();
});
