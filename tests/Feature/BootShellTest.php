<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('paints the boot shell for a signed-in athlete', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/inbox')
        ->assertOk()
        ->assertSee('id="boot-shell"', escape: false);
});

it('leaves the boot shell off guest pages', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertDontSee('id="boot-shell"', escape: false);
});
