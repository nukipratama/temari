<?php

declare(strict_types=1);

use App\Jobs\AI\FlushDeadLetterAlertJob;
use App\Services\Ops\MaintainerAlerter;
use Mockery\MockInterface;

it('delegates to MaintainerAlerter::flushDeadLetterWindow', function (): void {
    /** @var MaintainerAlerter&MockInterface $alerter */
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('flushDeadLetterWindow')->once();

    new FlushDeadLetterAlertJob()->handle($alerter);
});
