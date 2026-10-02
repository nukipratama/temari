<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-12 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * Seeds the demo athlete once and commits it outside the per-test
 * transaction, so every page below renders against the same dataset.
 */
function propContractDemoAthlete(): User
{
    $user = User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->first();
    if ($user instanceof User) {
        return $user;
    }

    Queue::fake();
    Notification::fake();
    expect(Artisan::call('demo:seed'))->toBe(0);

    DB::connection('mysql')->commit();
    DB::connection('analytics')->commit();
    DB::connection('mysql')->beginTransaction();
    DB::connection('analytics')->beginTransaction();

    return User::query()->where('email', DemoRunSeeder::DEMO_USER_EMAIL)->firstOrFail();
}

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/**
 * The page's own props, deferred ones included, split from the shared props
 * HandleInertiaRequests merges into every response.
 *
 * @return array{component: string, props: array<string, mixed>, shared: array<string, mixed>}
 */
function propContractPage(TestCase $test, ?User $user, string $url): array
{
    if ($user instanceof User) {
        $test->actingAs($user);
    }

    $html = (string) $test->get($url)->assertSuccessful()->getContent();
    preg_match('/type="application\/json">(.*?)<\/script>/s', $html, $matches);
    $page = json_decode(html_entity_decode($matches[1] ?? ''), true);
    expect($page)->toBeArray();

    $props = $page['props'];
    $deferred = collect($page['deferredProps'] ?? [])->flatten()->all();
    if ($deferred !== []) {
        $partial = $test->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) ($page['version'] ?? ''),
            'X-Inertia-Partial-Component' => $page['component'],
            'X-Inertia-Partial-Data' => implode(',', $deferred),
        ])->assertSuccessful()->json('props');
        $props = array_merge($props, $partial);
    }

    $request = Request::create($url);
    $request->setUserResolver(fn (): ?User => $user);
    $shared = array_keys(app(HandleInertiaRequests::class)->share($request));

    return [
        'component' => $page['component'],
        'props' => array_diff_key($props, array_flip($shared)),
        'shared' => array_intersect_key($props, array_flip($shared)),
    ];
}

/**
 * Every key path in the payload: `a.b` for nested objects, `a[].b` for the
 * union of a list's items, `a.*` for maps keyed by ids or dates.
 *
 * @return list<string>
 */
function propContractKeyPaths(mixed $value, string $prefix = ''): array
{
    if (! is_array($value) || $value === []) {
        return [];
    }

    $paths = [];
    if (array_is_list($value)) {
        foreach ($value as $item) {
            $paths = [...$paths, ...propContractKeyPaths($item, $prefix.'[]')];
        }
    } else {
        $dataKeyed = collect(array_keys($value))->every(fn (int|string $key): bool => is_int($key) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) === 1);
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.($dataKeyed ? '*' : $key);
            $paths = [...$paths, $path, ...propContractKeyPaths($child, $path)];
        }
    }

    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}

/**
 * @param  list<string>  $paths
 */
function propContractExpectFixture(array $paths, string $fixture, string $subject, string $interface): void
{
    $file = base_path("tests/fixtures/{$fixture}.json");

    if (getenv('UPDATE_INERTIA_PROP_FIXTURES') === '1') {
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    expect(File::exists($file))->toBeTrue("No fixture at tests/fixtures/{$fixture}.json; run `sail composer inertia-props:update`.");
    expect($paths)->toBe(
        json_decode(File::get($file), true),
        "{$subject} drifted from tests/fixtures/{$fixture}.json. If the change is intended, run `sail composer inertia-props:update` and update {$interface} to match.",
    );
}

it('emits the shared prop key paths its fixture records', function (string $name, Closure $url, bool $guest): void {
    $user = propContractDemoAthlete();
    $page = propContractPage($this, $guest ? null : $user, $url());

    propContractExpectFixture(propContractKeyPaths($page['shared']), "inertia-shared-props/{$name}", "The {$name} shared props", 'SharedProps in resources/js/types/inertia.ts');
})->with([
    'authenticated' => ['authenticated', fn (): string => route('dashboard'), false],
    'guest' => ['guest', fn (): string => route('login'), true],
]);

it('emits the prop key paths its fixture records', function (string $name, string $component, Closure $url, bool $guest = false): void {
    $user = propContractDemoAthlete();
    $page = propContractPage($this, $guest ? null : $user, $url($user));
    expect($page['component'])->toBe($component);

    propContractExpectFixture(propContractKeyPaths($page['props']), "inertia-props/{$name}", "{$component}'s props", "the page's props interface");
})->with([
    'Home' => ['Home', 'Home', fn (User $user): string => route('dashboard')],
    'History list' => ['History', 'History', fn (User $user): string => route('history')],
    'History calendar' => ['History.calendar', 'History', fn (User $user): string => route('history', ['view' => 'calendar'])],
    'Trends' => ['Trends', 'Trends', fn (User $user): string => route('trends')],
    'Profile' => ['Profile', 'Profile', fn (User $user): string => route('profile')],
    'Settings' => ['Settings/Index', 'Settings/Index', fn (User $user): string => route('settings')],
    'Race' => ['Race', 'Race', fn (User $user): string => route('race')],
    'Plan' => ['Plan', 'Plan', fn (User $user): string => route('plan')],
    'Inbox' => ['Inbox', 'Inbox', fn (User $user): string => route('inbox')],
    'Run' => ['Runs/Show', 'Runs/Show', fn (User $user): string => route('activities.show', $user->activities()->latest('id')->firstOrFail())],
    'Onboarding' => ['Onboarding/Index', 'Onboarding/Index', function (User $user): string {
        $user->forceFill(['onboarded_at' => null])->save();

        return route('onboarding.show');
    }],
    'Legal' => ['Legal/Document', 'Legal/Document', fn (User $user): string => route('legal.terms')],
    'Login' => ['Auth/Login', 'Auth/Login', fn (User $user): string => route('login'), true],
    'Devtools' => ['Devtools', 'Devtools', fn (User $user): string => route('devtools.index')],
    'Devtools design' => ['Devtools/Design', 'Devtools/Design', fn (User $user): string => route('devtools.design')],
    'Devtools feedback' => ['DevtoolsFeedback', 'DevtoolsFeedback', fn (User $user): string => route('devtools.feedback')],
    'Narration overview' => ['Narration/Overview', 'Narration/Overview', fn (User $user): string => route('devtools.narration')],
    'Narration athlete' => ['Narration/Athlete', 'Narration/Athlete', fn (User $user): string => route('devtools.narration.athlete', ['userId' => $user->id])],
]);

it('has a prop contract fixture for every page a controller renders', function (): void {
    $rendered = collect(File::allFiles(app_path()))
        ->flatMap(fn (SplFileInfo $file): array => preg_match_all("/Inertia::render\(\s*'([^']+)'/", (string) file_get_contents($file->getPathname()), $matches) > 0 ? $matches[1] : [])
        ->unique()
        ->sort()
        ->values();

    expect($rendered)->not->toBeEmpty()
        ->and($rendered->reject(fn (string $component): bool => File::exists(base_path("tests/fixtures/inertia-props/{$component}.json")))->values()->all())
        ->toBe([], 'These pages have no prop contract fixture; add them to the dataset above and run `sail composer inertia-props:update`.');
});
