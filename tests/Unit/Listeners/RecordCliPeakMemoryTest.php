<?php

declare(strict_types=1);

use App\Listeners\RecordCliPeakMemory;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Mockery\MockInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function mockPeakMemoryPulse(): MockInterface
{
    $entry = Mockery::mock(Entry::class);
    $entry->shouldReceive('max', 'avg', 'count')->andReturnSelf();
    Pulse::shouldReceive('record')->andReturn($entry);

    /** @var MockInterface */
    return Pulse::getFacadeRoot();
}

function peakMemoryJob(): Job
{
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ExampleJob');

    return $job;
}

function positiveBytes(): Closure
{
    return fn (mixed $bytes): bool => is_int($bytes) && $bytes > 0;
}

it('resets the peak', function (): void {
    $ballast = str_repeat('x', 32 * 1024 * 1024);
    unset($ballast);
    $before = memory_get_peak_usage();

    new RecordCliPeakMemory()->reset();

    expect(memory_get_peak_usage())->toBeLessThan($before);
});

it('records the peak per job class whether the job succeeded or failed', function (JobProcessed|JobFailed $event): void {
    $pulse = mockPeakMemoryPulse();

    new RecordCliPeakMemory()->job($event);

    $pulse->shouldHaveReceived('record')->with('peak_memory_job', 'App\\Jobs\\ExampleJob', Mockery::on(positiveBytes()))->once();
})->with([
    'processed' => fn (): JobProcessed => new JobProcessed('redis', peakMemoryJob()),
    'failed' => fn (): JobFailed => new JobFailed('redis', peakMemoryJob(), new RuntimeException('boom')),
]);

it('records the peak per artisan command', function (): void {
    $pulse = mockPeakMemoryPulse();

    new RecordCliPeakMemory()->command(new CommandFinished('runs:sync', new ArrayInput([]), new NullOutput(), 0));

    $pulse->shouldHaveReceived('record')->with('peak_memory_command', 'runs:sync', Mockery::on(positiveBytes()))->once();
});

it('is wired to the queue events', function (): void {
    $pulse = mockPeakMemoryPulse();

    dispatch(static function (): void {
    });

    $pulse->shouldHaveReceived('record')->with('peak_memory_job', Mockery::type('string'), Mockery::on(positiveBytes()))->once();
});
