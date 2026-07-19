<?php

declare(strict_types=1);

use App\Services\AI\MaintainerAlerter;

it('forwards the reason to the maintainer alerter and succeeds', function (): void {
    $alerter = Mockery::mock(MaintainerAlerter::class);
    app()->instance(MaintainerAlerter::class, $alerter);

    $alerter->shouldReceive('deployFailed')->once()->with('healthcheck failed');

    $this->artisan('deploy:alert', ['reason' => 'healthcheck failed'])
        ->assertSuccessful();
});
