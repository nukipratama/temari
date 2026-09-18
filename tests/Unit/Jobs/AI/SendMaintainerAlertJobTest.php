<?php

declare(strict_types=1);

use App\Jobs\AI\SendMaintainerAlertJob;
use App\Services\AI\MaintainerAlerter;

it('delegates to MaintainerAlerter::sendToAdmins with its message', function (): void {
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('sendToAdmins')->once()->with('hello maintainers');

    new SendMaintainerAlertJob('hello maintainers')->handle($alerter);
});
