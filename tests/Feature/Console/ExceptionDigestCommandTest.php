<?php

declare(strict_types=1);

use App\Services\Ops\MaintainerAlerter;
use App\Support\NewExceptionLedger;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    $this->freezeTime();
});

it('sends the new fingerprints in one digest and clears the list', function (): void {
    NewExceptionLedger::recordServer(new RuntimeException('athlete 42 failed'));
    NewExceptionLedger::recordBrowser('TypeError: user 7', "at f (https://temari.example/build/assets/app.js:1:2)", guest: false);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    app()->instance(MaintainerAlerter::class, $alerter);
    $alerter->shouldReceive('exceptionDigest')->once()->withArgs(
        fn (array $entries): bool => count($entries) === 2
            && str_starts_with($entries[0]['label'], 'RuntimeException at tests/Feature/Console/ExceptionDigestCommandTest.php:')
            && $entries[0]['count'] === 1,
    );

    $this->artisan('exceptions:digest')->assertSuccessful();

    expect(NewExceptionLedger::pull())->toBe([]);
});

it('sends nothing on a day with no new fingerprints', function (): void {
    $alerter = Mockery::mock(MaintainerAlerter::class);
    app()->instance(MaintainerAlerter::class, $alerter);
    $alerter->shouldNotReceive('exceptionDigest');

    $this->artisan('exceptions:digest')->assertSuccessful();
});
