<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the settings page for an authenticated user', function (): void {
    $this->actingAs(User::factory()->create())->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Index'));
});

it('requires auth', function (): void {
    $this->get('/settings')->assertRedirect('/login');
});

it('exposes the telegram connect url when the bot username is configured', function (): void {
    config(['services.telegram.bot_username' => 'temari_bot']);

    $this->actingAs(User::factory()->create())->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('telegram.connected', false)
            ->where('notificationPrefs.notifications_enabled', true)
            ->where('telegram.connect_url', fn (?string $url): bool => is_string($url)
                && str_starts_with($url, 'https://t.me/temari_bot?start=')));
});

it('reports the connection state and the channel-neutral preferences', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['username' => 'ada_runs']);
    NotificationPreference::factory()->for($user)->create([
        'notifications_enabled' => false,
        'telegram_enabled' => true,
        'push_enabled' => false,
    ]);

    $this->actingAs($user)->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('telegram.connected', true)
            ->where('telegram.username', 'ada_runs')
            ->where('notificationPrefs.notifications_enabled', false)
            ->where('notificationPrefs.telegram_enabled', true)
            ->where('notificationPrefs.push_enabled', false));
});

it('redirects the legacy /pengaturan path to the settings page', function (): void {
    $this->actingAs(User::factory()->create())->get('/pengaturan')
        ->assertRedirect('/settings');
});
