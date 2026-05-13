<?php

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Mockery\MockInterface;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->beforeEach(function (): void {
    Http::preventStrayRequests();
    // Feature tests render Inertia pages whose root template uses @vite(...).
    // We don't run `npm run build` in the pest CI job (deploy's healthcheck
    // verifies the actual built page works), so neutralize the directive
    // here. Tests assert on Inertia props via assertInertia, not on JS/CSS
    // tag presence.
    $this->withoutVite();
})->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function mockStravaDriver(callable $configure): MockInterface
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('redirectUrl')
        ->once()
        ->with(route('auth.strava.callback'))
        ->andReturnSelf();

    $configure($driver);

    Socialite::shouldReceive('driver')->once()->with('strava')->andReturn($driver);

    return $driver;
}
