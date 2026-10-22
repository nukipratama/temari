<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\NarrationOrigin;
use App\Services\Run\Story\Vibe;
use App\Support\Config\AppConfig;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Inertia\ResponseFactory;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;

uses(RefreshDatabase::class);

/**
 * A worker whose base app is the already-booted test app, so its requests run in per-request
 * clones with config/octane.php's listeners, as frankenphp-worker.php serves them in prod.
 */
function octaneWorkerOver(Application $app, FakeClient $client): FakeWorker
{
    $factory = new class ($app) extends ApplicationFactory {
        public function __construct(private readonly Application $booted)
        {
            parent::__construct($booted->basePath());
        }

        /** @param array<string, mixed> $initialInstances */
        public function createApplication(array $initialInstances = []): Application
        {
            foreach ($initialInstances as $key => $instance) {
                $this->booted->instance($key, $instance);
            }

            return $this->warm($this->booted);
        }
    };

    $worker = new FakeWorker($factory, $client);
    $worker->boot();

    return $worker;
}

/** @return array<string, mixed> */
function octaneRequestState(): array
{
    return [
        'user' => Auth::id(),
        'locale' => app('translator')->getLocale(),
        'inertiaProbe' => app(ResponseFactory::class)->getShared('octaneProbe'),
        AppConfig::class => app(AppConfig::class),
        NarrationOrigin::class => app(NarrationOrigin::class),
        AnalysisService::class => app(AnalysisService::class),
        Vibe::class => app(Vibe::class),
    ];
}

it('starts each worker request with no auth, locale, shared prop or scoped instance from the last', function (): void {
    $user = User::factory()->create();
    $client = new FakeClient([
        Request::create('/octane-probe/dirty'),
        Request::create('/octane-probe/observe'),
    ]);
    $worker = octaneWorkerOver($this->app, $client);
    $defaultLocale = config()->string('app.locale');

    /** @var list<array<string, mixed>> $seen */
    $seen = [];
    $router = $this->app->make(Router::class);
    $router->middleware('web')->get('/octane-probe/dirty', function () use ($user, &$seen) {
        Auth::login($user);
        app()->setLocale('id');
        app(ResponseFactory::class)->share('octaneProbe', true);
        $seen[] = octaneRequestState();

        return response()->noContent();
    });
    $router->middleware('web')->get('/octane-probe/observe', function () use (&$seen) {
        $seen[] = octaneRequestState();

        return response()->noContent();
    });

    try {
        $worker->run();
    } finally {
        $worker->terminate();
        CurrentApplication::set($this->app);
    }

    expect($client->errors)->toBe([])
        ->and($seen)->toHaveCount(2);
    [$dirty, $observed] = $seen;

    expect($dirty['user'])->toBe($user->id)
        ->and($dirty['inertiaProbe'])->toBeTrue()
        ->and($observed['user'])->toBeNull()
        ->and($observed['locale'])->toBe($defaultLocale)
        ->and($observed['inertiaProbe'])->toBeNull();

    foreach ([AppConfig::class, NarrationOrigin::class, AnalysisService::class, Vibe::class] as $scoped) {
        expect($observed[$scoped])->not->toBe($dirty[$scoped], "{$scoped} survived into the next request");
    }
});
