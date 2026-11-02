<?php

declare(strict_types=1);

use App\Enums\PlanOverrideKind;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** @return array{user: User, session: PlannedSession} */
function overrideControllerFixture(bool $demo = false): array
{
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create(['is_demo' => $demo]);
    seedDemandingRunYesterday($user);
    $session = PlannedSession::factory()->for($user)->create(['date' => '2026-08-12', 'session_type' => SessionType::Interval]);
    PlanAdaptation::factory()->for($user)->create(['week_start' => '2026-08-10']);

    return ['user' => $user, 'session' => $session];
}

it('takes and undoes the week\'s override on the athlete\'s own day', function (): void {
    ['user' => $user, 'session' => $session] = overrideControllerFixture();

    $this->actingAs($user)->post("/plan/sessions/{$session->id}/override")->assertRedirect()->assertSessionHasNoErrors();
    expect(PlanAdaptation::query()->firstOrFail()->override_kind)->toBe(PlanOverrideKind::ReadinessEase);

    $this->actingAs($user)->delete("/plan/sessions/{$session->id}/override")->assertRedirect()->assertSessionHasNoErrors();
    expect(PlanAdaptation::query()->firstOrFail()->override_kind)->toBeNull();
});

it('says why a day cannot be overridden', function (): void {
    ['user' => $user, 'session' => $session] = overrideControllerFixture();
    $session->update(['session_type' => SessionType::Easy]);

    $this->actingAs($user)->post("/plan/sessions/{$session->id}/override")->assertSessionHasErrors('override');
});

it('hides another athlete\'s day', function (string $method): void {
    ['session' => $session] = overrideControllerFixture();

    $this->actingAs(User::factory()->create())->call($method, "/plan/sessions/{$session->id}/override")->assertNotFound();
})->with(['POST', 'DELETE']);

it('refuses the demo athlete', function (): void {
    ['user' => $user, 'session' => $session] = overrideControllerFixture(demo: true);

    $this->actingAs($user)->post("/plan/sessions/{$session->id}/override", [], ['X-Inertia' => 'true'])->assertSessionHasErrors('demo');
    expect(PlanAdaptation::query()->firstOrFail()->override_kind)->toBeNull();
});

it('throttles overrides with the plan edits', function (string $name): void {
    expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:20,1,plan-edit', 'block-demo-writes');
})->with(['plan.sessions.override.store', 'plan.sessions.override.destroy']);
