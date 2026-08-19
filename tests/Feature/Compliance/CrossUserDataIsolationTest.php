<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Strava's API terms have barred showing one athlete's activity data to another
 * user since Nov 2024. These sweeps are generative on the route table rather
 * than a list of today's endpoints, so a new endpoint is covered the moment it
 * is registered: an unmapped route parameter fails the first test outright.
 */
beforeEach(function (): void {
    $this->marker = 'CrossUserIsolationMarkerRun';
    $this->victim = User::factory()->create();
    $this->attacker = User::factory()->create();

    $this->victimActivity = Activity::factory()->for($this->victim)->create();
    ActivityDetail::factory()->for($this->victimActivity)->create(['name' => $this->marker]);
    $this->victimCard = RunCard::factory()->for($this->victimActivity)->create();
    $this->victimSession = PlannedSession::factory()->for($this->victim)->create();
    $this->victimSnapshot = WeeklySnapshot::factory()->for($this->victim)->create();
    $this->victimNotification = InboxNotification::factory()->for($this->victim)->create();
});

/**
 * Every route that answers a signed-in user. `onboarded` counts alongside `auth`
 * so `/`, which drops `auth` to branch guest-vs-dashboard in the controller,
 * stays in the sweep instead of silently falling out of it.
 *
 * @return list<RegisteredRoute>
 */
function authenticatedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RegisteredRoute $route): bool => array_intersect(
            ['auth', 'onboarded'],
            $route->gatherMiddleware(),
        ) !== [])
        ->reject(fn (RegisteredRoute $route): bool => str_starts_with(
            ltrim((string) $route->getAction('controller'), '\\'),
            RedirectController::class,
        ))
        ->values()
        ->all();
}

it('refuses every authenticated route that is handed another user\'s resource id', function (): void {
    $ownedParameters = [
        'activity' => fn (): int => $this->victimActivity->id,
        'card' => fn (): int => $this->victimCard->id,
        'plannedSession' => fn (): int => $this->victimSession->id,
        'notification' => fn (): int => $this->victimNotification->id,
        'snapshot' => fn (): int => $this->victimSnapshot->id,
        'subjectId' => fn (): int => $this->victimActivity->id,
    ];

    // Parameters that name no owner: the controller keys its lookup on the
    // signed-in user, so there is no foreign id an attacker could substitute.
    $unownedParameters = [
        'type' => 'run_insight',
        'month' => '2026-05',
    ];

    $unmapped = [];
    $leaked = [];
    $reachable = [];
    $checked = 0;

    foreach (authenticatedRoutes() as $route) {
        $parameters = $route->parameterNames();
        if ($parameters === []) {
            continue;
        }

        $missing = array_diff($parameters, array_keys($ownedParameters), array_keys($unownedParameters));
        if ($missing !== []) {
            $unmapped[] = $route->uri().' → '.implode(', ', $missing);

            continue;
        }

        $uri = '/'.ltrim($route->uri(), '/');
        foreach ($parameters as $parameter) {
            $value = isset($ownedParameters[$parameter])
                ? ($ownedParameters[$parameter])()
                : $unownedParameters[$parameter];
            $uri = str_replace(['{'.$parameter.'}', '{'.$parameter.'?}'], (string) $value, $uri);
        }

        $method = collect($route->methods())
            ->reject(fn (string $verb): bool => in_array($verb, ['HEAD', 'OPTIONS'], true))
            ->firstOrFail();

        $response = $this->actingAs($this->attacker)->call($method, $uri);
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();

        if (array_intersect($parameters, array_keys($ownedParameters)) !== [] && ! in_array($status, [403, 404], true)) {
            $reachable[] = "{$method} {$uri} answered {$status}";
        }

        if (str_contains($body, (string) $this->marker)) {
            $leaked[] = "{$method} {$uri}";
        }

        $checked++;
    }

    expect($unmapped)->toBe(
        [],
        "These authenticated routes carry a parameter this sweep does not know how to build.\n"
        ."Register it in \$ownedParameters (it resolves to a row a user owns) or \$unownedParameters (it does not):\n  "
        .implode("\n  ", $unmapped),
    );
    expect($reachable)->toBe(
        [],
        "These routes accepted another user's resource id instead of answering 403/404:\n  ".implode("\n  ", $reachable),
    );
    expect($leaked)->toBe(
        [],
        "These routes rendered another user's activity data:\n  ".implode("\n  ", $leaked),
    );
    expect($checked)->toBeGreaterThan(0);
});

it('never renders another user\'s activity data on a shared page', function (): void {
    $leaked = [];
    $visited = 0;

    foreach (authenticatedRoutes() as $route) {
        if ($route->parameterNames() !== [] || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = '/'.ltrim($route->uri(), '/');
        $body = (string) $this->actingAs($this->attacker)->get($uri)->getContent();

        if (str_contains($body, (string) $this->marker)) {
            $leaked[] = $uri;
        }

        $visited++;
    }

    expect($leaked)->toBe(
        [],
        "These pages rendered another user's activity data to a signed-in stranger:\n  ".implode("\n  ", $leaked),
    );
    expect($visited)->toBeGreaterThan(0);
});

it('renders the marker run to its own owner, so the sweeps are not vacuous', function (): void {
    $this->actingAs($this->victim)
        ->get('/history')
        ->assertSuccessful()
        ->assertSee($this->marker, false);
});
