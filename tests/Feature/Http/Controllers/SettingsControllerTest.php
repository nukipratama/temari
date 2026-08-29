<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\TelegramConnection;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Support\DataUseStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the settings page for an authenticated user', function (): void {
    $this->actingAs(User::factory()->create())->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Index'));
});

it('hands the page the shared data-use statement', function (): void {
    $this->actingAs(User::factory()->create())->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dataUse.headline', DataUseStatement::HEADLINE)
            ->where('dataUse.points', DataUseStatement::points()));
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

it('hands the page the config-fallback HR-zones profile for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hrZones.source', 'default')
            ->where('hrZones.stravaSyncedLabel', null)
            ->where('hrZones.profile.max_hr', 180)
            ->where('hrZones.profile.resting_hr', 55)
            ->where('hrZones.profile.hr_zones.Z1.lo', 116));
});

it('exposes the strava source and a last-synced label for a synced HR profile', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create([
        'source' => 'strava',
        'strava_zones_synced_at' => now(),
    ]);

    $this->actingAs($user)->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hrZones.source', 'strava')
            ->whereType('hrZones.stravaSyncedLabel', 'string'));
});

it('hands the page the stored custom HR-zones profile', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 195]);

    $this->actingAs($user)->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hrZones.profile.max_hr', 195));
});

it('exposes canSyncFromStrava true only with a live profile:read_all connection', function (): void {
    $scoped = User::factory()->create();
    StravaConnection::factory()->for($scoped)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $this->actingAs($scoped)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->where('hrZones.canSyncFromStrava', true));

    $unscoped = User::factory()->create();
    StravaConnection::factory()->for($unscoped)->create(['scopes' => 'read,activity:read_all']);

    $this->actingAs($unscoped)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->where('hrZones.canSyncFromStrava', false));

    $none = User::factory()->create();
    $this->actingAs($none)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->where('hrZones.canSyncFromStrava', false));
});

it('hands the page all-null training preferences for a fresh user', function (): void {
    $this->actingAs(User::factory()->create())->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('trainingPreferences.experience_level', null)
            ->where('trainingPreferences.sessions_per_week', null)
            ->where('trainingPreferences.run_days', null));
});

it('hands the page the stored training preferences', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create([
        'experience_level' => 'experienced',
        'sessions_per_week' => 5,
        'run_days' => [0, 1, 3, 5, 6],
        'long_run_day' => 6,
    ]);

    $this->actingAs($user)->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('trainingPreferences.experience_level', 'experienced')
            ->where('trainingPreferences.sessions_per_week', 5)
            ->where('trainingPreferences.run_days', [0, 1, 3, 5, 6])
            ->where('trainingPreferences.long_run_day', 6));
});
