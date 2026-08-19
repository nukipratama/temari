<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('serves the landing page to a guest', function (): void {
    $this->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->has('authStravaUrl')
            ->has('dataUse')
            ->has('trainingDisclaimer'));
});

it('serves the dashboard to a signed-in user', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Home'));
});

it('sends a signed-in user who has not onboarded to the wizard', function (): void {
    $this->actingAs(User::factory()->create(['onboarded_at' => null]))
        ->get('/')
        ->assertRedirect(route('onboarding.show'));
});

it('runs none of the dashboard reads for a guest', function (): void {
    $queried = [];
    DB::listen(function ($query) use (&$queried): void {
        $queried[] = $query->sql;
    });

    $this->get('/')->assertSuccessful();

    expect(implode("\n", $queried))
        ->not->toContain('activity_details')
        ->not->toContain('story_lines')
        ->not->toContain('weekly_snapshots');
});
