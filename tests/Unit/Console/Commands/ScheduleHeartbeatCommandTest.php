<?php

declare(strict_types=1);

use App\Console\Commands\ScheduleHeartbeatCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;

afterEach(fn () => Carbon::setTestNow());

/** Redis not available in CI — a connection mock serving a stored stamp of the given age. */
function heartbeatRedisAged(?int $ageSeconds): MockInterface
{
    $mock = Mockery::mock();
    $mock->shouldReceive('get')
        ->with(ScheduleHeartbeatCommand::KEY)
        ->andReturn($ageSeconds === null ? false : (string) (Carbon::now()->getTimestamp() - $ageSeconds));

    return $mock;
}

function heartbeatRedisUnreachable(): void
{
    Redis::shouldReceive('connection')->with('default')->andThrow(new RuntimeException('Connection refused'));
}

it('stamps the current timestamp with a TTL on the durable default connection', function (): void {
    Carbon::setTestNow('2026-07-29 10:00:00');

    $connection = Mockery::mock();
    $connection->shouldReceive('setex')
        ->once()
        ->with(
            ScheduleHeartbeatCommand::KEY,
            ScheduleHeartbeatCommand::KEY_TTL_SECONDS,
            (string) Carbon::now()->getTimestamp(),
        )
        ->andReturnTrue();

    // 'default' is the durable noeviction instance; 'cache' runs allkeys-lru and
    // could evict the stamp into a false "scheduler dead".
    Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);

    $this->artisan('schedule:heartbeat')->assertSuccessful();
});

it('reports a redis outage on the write path instead of throwing a trace every minute', function (): void {
    heartbeatRedisUnreachable();

    $this->artisan('schedule:heartbeat')
        ->expectsOutputToContain('scheduler heartbeat NOT written: redis unreachable')
        ->assertFailed();
});

it('checks a stored stamp of a given age', function (?int $age, string $expectedOutput, bool $succeeds): void {
    Redis::shouldReceive('connection')->with('default')->andReturn(heartbeatRedisAged($age));

    $result = $this->artisan('schedule:heartbeat', ['--check' => true])
        ->expectsOutputToContain($expectedOutput);

    $succeeds ? $result->assertSuccessful() : $result->assertFailed();
})->with([
    'fresh stamp passes' => [ScheduleHeartbeatCommand::STALE_AFTER_SECONDS, 'scheduler heartbeat OK', true],
    'stale stamp fails and reports its age' => [
        ScheduleHeartbeatCommand::STALE_AFTER_SECONDS + 73,
        'scheduler heartbeat STALE: '.(ScheduleHeartbeatCommand::STALE_AFTER_SECONDS + 73).'s old',
        false,
    ],
    'missing stamp fails' => [null, 'scheduler heartbeat MISSING', false],
]);

it('fails the check with a distinct message when redis itself is unreachable', function (): void {
    heartbeatRedisUnreachable();

    $this->artisan('schedule:heartbeat', ['--check' => true])
        ->expectsOutputToContain('scheduler heartbeat UNKNOWN: redis unreachable')
        ->assertFailed();
});
