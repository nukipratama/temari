<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceMaintenanceMode;
use App\Jobs\Telegram\HandleTelegramUpdateJob;
use App\Models\User;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

beforeEach(fn () => app()->maintenanceMode()->activate([]));

it('serves the maintenance page to a guest with a 503 and Retry-After', function (): void {
    $this->get('/')
        ->assertServiceUnavailable()
        ->assertHeader('Retry-After', (string) EnforceMaintenanceMode::RETRY_AFTER_SECONDS)
        ->assertSee('back in a bit')
        ->assertSee("temari's getting some work done", false);
});

it('serves the maintenance page to a signed-in athlete who is not an admin', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('history'))
        ->assertServiceUnavailable()
        ->assertSee('back in a bit');
});

it('closes the signed-in demo account too', function (): void {
    $this->actingAs(User::factory()->demo()->create())
        ->get('/')
        ->assertServiceUnavailable();
});

it('lets an admin use the app normally', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('history'))
        ->assertOk();
});

it('lets an admin reach the Pulse page in production, so maintenance can always be switched off', function (): void {
    config(['devtools.password' => 'secret']);
    app()->detectEnvironment(fn (): string => 'production');

    $this->actingAs(User::factory()->admin()->create())
        ->get('/devtools/pulse', ['PHP_AUTH_USER' => 'owner', 'PHP_AUTH_PW' => 'secret'])
        ->assertOk()
        ->assertSee('Maintenance');
});

it('lets the Pulse toggle switch maintenance off over HTTP while it is on', function (): void {
    $page = $this->actingAs(User::factory()->admin()->create())->get('/devtools/pulse')->assertOk();

    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded))
        ->first(fn (string $json): bool => json_decode($json, true)['memo']['name'] === 'pulse.system-control');
    expect($snapshot)->not->toBeNull();

    $update = Route::getRoutes()->getByName('livewire.update');
    $this->postJson('/'.$update->uri(), ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => 'toggleMaintenance', 'params' => []]],
    ]]], ['X-Livewire' => '1'])->assertOk();

    expect(app()->isDownForMaintenance())->toBeFalse();
});

it('keeps the password-gated devtools reachable without an admin session', function (): void {
    config(['devtools.password' => 'secret']);
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/devtools/pulse', ['PHP_AUTH_USER' => 'owner', 'PHP_AUTH_PW' => 'secret'])->assertOk();
    $this->get('/devtools/pulse')->assertUnauthorized();
});

it('keeps the deploy smoke test and health check green', function (): void {
    // /up's dependency probes (MySQL, Redis, Horizon's master) are
    // VerifyDependenciesTest's concern; this proves maintenance doesn't block it.
    Event::fake([DiagnosingHealth::class]);

    $this->get('/up')->assertOk();
    $this->get(route('login'))->assertOk();
});

it('keeps the admin sign-in path open', function (): void {
    mockStravaDriver(function ($driver): void {
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://www.strava.com/oauth/authorize?fake'));
    });

    $this->get(route('auth.strava.redirect'))->assertRedirect('https://www.strava.com/oauth/authorize?fake');
});

it('hides the demo button and refuses the demo sign-in', function (): void {
    config(['demo.login_enabled' => true]);
    User::factory()->demo()->create();

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('demoLoginEnabled', false));

    $this->post(route('auth.demo'))->assertServiceUnavailable();
    $this->assertGuest();
});

it('shows the demo button again once maintenance lifts', function (): void {
    config(['demo.login_enabled' => true]);
    app()->maintenanceMode()->deactivate();

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('demoLoginEnabled', true));
});

it('keeps the Strava webhook handshake answering', function (): void {
    config(['services.strava.webhook_verify_token' => 'verify-token']);

    $this->getJson(route('strava.webhook.verify', [
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'verify-token',
        'hub.challenge' => 'challenge-abc',
    ]))->assertOk()->assertExactJson(['hub.challenge' => 'challenge-abc']);
});

it('keeps accepting Telegram updates onto the queue', function (): void {
    Bus::fake();
    config(['services.telegram.webhook_secret' => 'top-secret']);

    $this->postJson(route('telegram.webhook.handle'), ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'top-secret'])
        ->assertOk();

    Bus::assertDispatched(HandleTelegramUpdateJob::class);
});

it('answers JSON callers with a JSON 503', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson(route('history'))
        ->assertServiceUnavailable()
        ->assertHeader('Retry-After')
        ->assertJson(['message' => 'Temari is under maintenance.']);
});

it('sends an open Inertia app to a full page load so the maintenance page replaces it', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('history'), ['X-Inertia' => 'true'])
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertHeader('X-Inertia-Location', route('history'));
});

it('does nothing while maintenance is off', function (): void {
    app()->maintenanceMode()->deactivate();

    $this->actingAs(User::factory()->create())
        ->get(route('history'))
        ->assertOk();
});
