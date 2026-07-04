<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('blocks a demo user from a mutating route with an Indonesian flash error', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama']);

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->post('/api/aksesori/equip', ['unlock_key' => 'accessory.medal_pertama'])
        ->assertRedirect()
        ->assertSessionHasErrors(['demo' => 'Akun demo cuma bisa dilihat, gak bisa diubah.']);

    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.medal_pertama')
        ->value('equipped'))->toBeFalse();
});

it('returns a JSON 403 to a plain fetch (non-Inertia) mutating request from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama']);

    $this->actingAs($user)
        ->postJson('/api/aksesori/equip', ['unlock_key' => 'accessory.medal_pertama'])
        ->assertStatus(403)
        ->assertJson(['message' => 'Akun demo cuma bisa dilihat, gak bisa diubah.']);
});

it('does not block a normal user from the same mutating route', function (): void {
    $user = User::factory()->create(['is_demo' => false]);
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama']);

    $this->actingAs($user)
        ->post('/api/aksesori/equip', ['unlock_key' => 'accessory.medal_pertama'])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.medal_pertama')
        ->value('equipped'))->toBeTrue();
});

it('does not block a GET read from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)->get('/aksesori')->assertSuccessful();
});

it('still lets the demo user log out', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('still lets the demo user trigger "Baca ulang" (a real on-demand LLM call)', function (): void {
    Bus::fake();
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_headline/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful();
});
