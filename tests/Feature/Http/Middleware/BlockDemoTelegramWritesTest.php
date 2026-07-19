<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\RunCard;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('blocks a demo user from an Inertia notification-preference write with an Indonesian flash error', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->patch('/profil/notifikasi', [
            'post_run' => false,
            'weekly_recap' => true,
            'monthly_recap' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['demo' => 'Akun demo cuma bisa dilihat, gak bisa diubah.']);

    expect($user->notificationPreference()->exists())->toBeFalse();
});

it('returns a JSON 403 to a plain fetch on the notification test endpoint from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/profil/notifikasi/test')
        ->assertStatus(403)
        ->assertJson(['message' => 'Akun demo cuma bisa dilihat, gak bisa diubah.']);
});

it('does not block a normal user from the same notification-preference write', function (): void {
    $user = User::factory()->create(['is_demo' => false]);

    $this->actingAs($user)
        ->patch('/profil/notifikasi', [
            'post_run' => false,
            'weekly_recap' => true,
            'monthly_recap' => true,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect($user->notificationPreference->post_run)->toBeFalse();
});

it('does not block a demo user from equipping an accessory (interactive sandbox)', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
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

it('does not block a demo user from marking a card seen (passive UX write)', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    $card = RunCard::factory()->for(Activity::factory()->for($user))->create();

    expect($this->actingAs($user)->postJson("/api/kartu/{$card->id}/seen")->status())
        ->not->toBe(403);
});

it('does not block a GET read from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)->get('/profil')->assertSuccessful();
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
