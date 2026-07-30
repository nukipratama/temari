<?php

declare(strict_types=1);

use App\Jobs\AI\FlushDeadLetterAlertJob;
use App\Services\AI\MaintainerAlerter;

it('delegates to MaintainerAlerter::flushDeadLetterWindow', function (): void {
    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('flushDeadLetterWindow')->once();

    new FlushDeadLetterAlertJob()->handle($alerter);
});
