<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Strava's API terms have barred showing one athlete's activity data to another
 * user since Nov 2024. These sweeps are generative on the route table rather
 * than a list of today's endpoints, so a new endpoint is covered the moment it
 * is registered: an unmapped route parameter fails the first test outright.
 *
 * @return array{marker: string, victim: User, attacker: User, victimActivity: Activity, victimCard: RunCard, victimSession: PlannedSession, victimSnapshot: WeeklySnapshot, victimNotification: InboxNotification, victimRace: RaceGoal}
 */
function crossUserFixture(): array
{
    $marker = 'CrossUserIsolationMarkerRun';
    $victim = User::factory()->create();
    $attacker = User::factory()->create();

    $victimActivity = Activity::factory()->for($victim)->create();
    ActivityDetail::factory()->for($victimActivity)->create(['name' => $marker]);

    return [
        'marker' => $marker,
        'victim' => $victim,
        'attacker' => $attacker,
        'victimActivity' => $victimActivity,
        'victimCard' => RunCard::factory()->for($victimActivity)->create(),
        'victimSession' => PlannedSession::factory()->for($victim)->create(),
        'victimSnapshot' => WeeklySnapshot::factory()->for($victim)->create(),
        'victimNotification' => InboxNotification::factory()->for($victim)->create(),
        'victimRace' => RaceGoal::factory()->for($victim)->completed()->create(),
    ];
}

/**
 * Every route that answers a signed-in user. `onboarded` counts alongside `auth`
 * so `/`, which drops `auth` to branch guest-vs-dashboard in the controller,
 * stays in the sweep instead of silently falling out of it.
 *
 * @return list<RegisteredRoute>
 */
function authenticatedRoutes(): array
{
    return array_values(collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RegisteredRoute $route): bool => array_intersect(
            ['auth', 'onboarded'],
            $route->gatherMiddleware(),
        ) !== [])
        ->reject(fn (RegisteredRoute $route): bool => str_starts_with(
            ltrim($route->getActionName(), '\\'),
            RedirectController::class,
        ))
        ->all());
}

/**
 * The deferred blocks of a page, fetched the way `<Deferred>` fetches them: a
 * block that only arrives on the follow-up request would otherwise leave the
 * sweep blind to whatever it renders.
 *
 * @param  TestCase  $actingAs  The authenticated test case.
 */
function deferredBodyFor(TestCase $actingAs, string $uri, string $html): string
{
    preg_match('/type="application\/json">(.*?)<\/script>/s', $html, $matches);
    $page = json_decode(html_entity_decode($matches[1] ?? ''), true);

    if (! is_array($page)) {
        return '';
    }

    $deferred = collect(arrayOf($page['deferredProps'] ?? []))
        ->flatten()
        ->map(fn (mixed $prop): string => is_string($prop) ? $prop : throw new InvalidArgumentException('Deferred prop is not a string.'))
        ->all();

    if ($deferred === []) {
        return '';
    }

    $version = $page['version'] ?? '';
    $component = $page['component'] ?? '';

    return (string) $actingAs->get($uri, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => is_string($version) ? $version : throw new InvalidArgumentException('Inertia version is not a string.'),
        'X-Inertia-Partial-Component' => is_string($component) ? $component : throw new InvalidArgumentException('Inertia component is not a string.'),
        'X-Inertia-Partial-Data' => implode(',', $deferred),
    ])->getContent();
}

it('refuses every authenticated route that is handed another user\'s resource id', function (): void {
    $fixture = crossUserFixture();
    $ownedParameters = [
        'activity' => fn (): int => $fixture['victimActivity']->id,
        'card' => fn (): int => $fixture['victimCard']->id,
        'plannedSession' => fn (): int => $fixture['victimSession']->id,
        'notification' => fn (): int => $fixture['victimNotification']->id,
        'snapshot' => fn (): int => $fixture['victimSnapshot']->id,
        'race' => fn (): int => $fixture['victimRace']->id,
        'subjectId' => fn (): int => $fixture['victimActivity']->id,
    ];

    // Parameters that name no owner: the controller keys its lookup on the
    // signed-in user, so there is no foreign id an attacker could substitute.
    $unownedParameters = [
        'type' => 'run_insight',
        'month' => '2026-05',
        'date' => '2026-05-04',
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

        $response = $this->actingAs($fixture['attacker'])->call($method, $uri);
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();

        if (array_intersect($parameters, array_keys($ownedParameters)) !== [] && ! in_array($status, [403, 404], true)) {
            $reachable[] = "{$method} {$uri} answered {$status}";
        }

        if (str_contains($body, $fixture['marker'])) {
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
    $fixture = crossUserFixture();
    $leaked = [];
    $visited = 0;

    foreach (authenticatedRoutes() as $route) {
        if ($route->parameterNames() !== [] || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = '/'.ltrim($route->uri(), '/');
        $body = (string) $this->actingAs($fixture['attacker'])->get($uri)->getContent();
        $body .= deferredBodyFor($this->actingAs($fixture['attacker']), $uri, $body);

        if (str_contains($body, $fixture['marker'])) {
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
    $fixture = crossUserFixture();
    $html = (string) $this->actingAs($fixture['victim'])->get('/history')
        ->assertSuccessful()
        ->getContent();

    expect(deferredBodyFor($this->actingAs($fixture['victim']), '/history', $html))
        ->toContain($fixture['marker']);
});

it('turns every signed-in route away from a guest', function (): void {
    crossUserFixture();
    $notRedirected = [];
    $notUnauthorised = [];
    $checked = 0;

    foreach (authenticatedRoutes() as $route) {
        if ($route->uri() === '/') {
            continue;
        }

        $uri = '/'.ltrim((string) preg_replace('/\{(\w+)\??\}/', '1', $route->uri()), '/');
        $method = collect($route->methods())
            ->reject(fn (string $verb): bool => in_array($verb, ['HEAD', 'OPTIONS'], true))
            ->firstOrFail();

        $page = $this->call($method, $uri);
        if (! $page->isRedirect(url('/login'))) {
            $notRedirected[] = "{$method} {$uri} answered {$page->getStatusCode()}";
        }

        $json = $this->json($method, $uri);
        if ($json->getStatusCode() !== 401) {
            $notUnauthorised[] = "{$method} {$uri} answered {$json->getStatusCode()}";
        }

        $checked++;
    }

    expect($notRedirected)->toBe([], "These routes did not redirect a guest to /login:\n  ".implode("\n  ", $notRedirected));
    expect($notUnauthorised)->toBe([], "These routes did not answer a guest's JSON request with 401:\n  ".implode("\n  ", $notUnauthorised));
    expect($checked)->toBeGreaterThan(0);
});
