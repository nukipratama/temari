<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the settings page for an authenticated user', function (): void {
    $this->actingAs(User::factory()->create())->get('/pengaturan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Pengaturan/Index'));
});

it('requires auth', function (): void {
    $this->get('/pengaturan')->assertRedirect('/login');
});

it('exposes the telegram connect url when the bot username is configured', function (): void {
    config(['services.telegram.bot_username' => 'temari_bot']);

    $this->actingAs(User::factory()->create())->get('/pengaturan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('telegram.connected', false)
            ->where('notificationPrefs.post_run', true)
            ->where('notificationPrefs.weekly_recap', true)
            ->where('notificationPrefs.monthly_recap', true)
            ->where('telegram.connect_url', fn (?string $url): bool => is_string($url)
                && str_starts_with($url, 'https://t.me/temari_bot?start=')));
});

it('reports the connection state and the channel-neutral preferences', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['username' => 'ada_runs']);
    NotificationPreference::factory()->for($user)->create([
        'post_run' => false,
        'weekly_recap' => true,
        'monthly_recap' => false,
    ]);

    $this->actingAs($user)->get('/pengaturan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('telegram.connected', true)
            ->where('telegram.username', 'ada_runs')
            ->where('notificationPrefs.post_run', false)
            ->where('notificationPrefs.weekly_recap', true)
            ->where('notificationPrefs.monthly_recap', false));
});

it('redirects the legacy /settings path to the settings page', function (): void {
    $this->actingAs(User::factory()->create())->get('/settings')
        ->assertRedirect('/pengaturan');
});
