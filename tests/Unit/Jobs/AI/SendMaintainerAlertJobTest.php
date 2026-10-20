<?php

declare(strict_types=1);

use App\Jobs\AI\SendMaintainerAlertJob;
use App\Services\Ops\MaintainerAlerter;
use Mockery\MockInterface;

it('delegates to MaintainerAlerter::sendToAdmins with its message', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('sendToAdmins')->once()->with('hello maintainers');

    new SendMaintainerAlertJob('hello maintainers')->handle($alerter);
});
