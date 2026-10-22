<?php

declare(strict_types=1);

use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Mockery\MockInterface;

function mockRequestPeakMemoryPulse(): MockInterface
{
    $entry = Mockery::mock(Entry::class);
    $entry->shouldReceive('max', 'avg', 'count')->andReturnSelf();
    Pulse::shouldReceive('record')->andReturn($entry);

    /** @var MockInterface */
    return Pulse::getFacadeRoot();
}

it('records the peak per route', function (string $path, string $key): void {
    $pulse = mockRequestPeakMemoryPulse();

    $this->get($path);

    $pulse->shouldHaveReceived('record')
        ->with('peak_memory_request', $key, Mockery::on(fn (mixed $bytes): bool => is_int($bytes) && $bytes > 0))
        ->once();
})->with([
    'matched route by its uri' => ['/ready', 'GET ready'],
    'unmatched path as one key' => ['/no-such-page', 'unmatched'],
]);
