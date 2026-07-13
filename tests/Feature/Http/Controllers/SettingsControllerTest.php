<?php

declare(strict_types=1);

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
            ->where('telegram.notify_post_run', true)
            ->where('telegram.notify_weekly_recap', true)
            ->where('telegram.notify_monthly_recap', true)
            ->where('telegram.notify_daily_briefing', false)
            ->where('telegram.connect_url', fn (?string $url): bool => is_string($url)
                && str_starts_with($url, 'https://t.me/temari_bot?start=')));
});

it('reports the telegram connection state and preferences when connected', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create([
        'username' => 'ada_runs',
        'notify_post_run' => false,
        'notify_weekly_recap' => true,
        'notify_monthly_recap' => false,
        'notify_daily_briefing' => true,
    ]);

    $this->actingAs($user)->get('/pengaturan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('telegram.connected', true)
            ->where('telegram.username', 'ada_runs')
            ->where('telegram.notify_post_run', false)
            ->where('telegram.notify_weekly_recap', true)
            ->where('telegram.notify_monthly_recap', false)
            ->where('telegram.notify_daily_briefing', true));
});

it('redirects the legacy /settings path to the settings page', function (): void {
    $this->actingAs(User::factory()->create())->get('/settings')
        ->assertRedirect('/pengaturan');
});
