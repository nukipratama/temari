<?php

declare(strict_types=1);

use Mockery\MockInterface;
use App\Listeners\VerifyDependencies;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/** A Redis connection mock whose write-probe round-trips the sentinel back as '1'. */
function healthyProbeRedis(): MockInterface
{
    $mock = Mockery::mock();
    $mock->shouldReceive('setex')->andReturnTrue();
    $mock->shouldReceive('get')->andReturn('1');
    $mock->shouldReceive('del')->andReturn(1);

    return $mock;
}

it('passes when mysql, analytics db, both redis connections, and horizon all respond', function (): void {
    // Horizon workers don't run during Pest, so mock a running master.
    $this->mock(MasterSupervisorRepository::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([(object) ['status' => 'running']]);
    });

    // Redis not available in CI — mock a healthy write-probe round-trip.
    Redis::shouldReceive('connection')->with('default')->andReturn(healthyProbeRedis());
    Redis::shouldReceive('connection')->with('cache')->andReturn(healthyProbeRedis());

    app(VerifyDependencies::class)->handle(new DiagnosingHealth());

    expect(true)->toBeTrue();
});

it('throws when the redis write-probe reads back the wrong value (server loading its AOF)', function (): void {
    // setex + get succeed at the wire level but the read-back is not the sentinel
    // (a LOADING replica answers reads with stale/empty data), so the probe fails.
    $loading = Mockery::mock();
    $loading->shouldReceive('setex')->andReturnTrue();
    $loading->shouldReceive('get')->andReturnNull();
    $loading->shouldReceive('del')->andReturn(0);
    Redis::shouldReceive('connection')->with('default')->andReturn($loading);

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: redis [default] unreachable');
});

it('throws when mysql is unreachable', function (): void {
    DB::shouldReceive('connection')->andThrow(new RuntimeException('down'));

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: mysql unreachable');
});

it('throws when the analytics db is unreachable', function (): void {
    // Real connection attempt to a port nothing listens on, so the default
    // connection (untouched config) still succeeds for real alongside it.
    config(['database.connections.analytics.host' => '127.0.0.1', 'database.connections.analytics.port' => 1]);
    DB::purge('analytics');

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: analytics db unreachable');
});

it('throws naming the redis connection that is unreachable', function (): void {
    $dead = Mockery::mock();
    $dead->shouldReceive('setex')->andThrow(new RuntimeException('boom'));
    Redis::shouldReceive('connection')->with('default')->andReturn($dead);

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: redis [default] unreachable');
});

it('throws naming the cache redis connection when default succeeds but cache fails', function (): void {
    // The loop checks 'default' first and stops at the first failure, so this
    // is the only way to reach — and prove — the second iteration.
    $dead = Mockery::mock();
    $dead->shouldReceive('setex')->andThrow(new RuntimeException('boom'));
    Redis::shouldReceive('connection')->with('default')->andReturn(healthyProbeRedis());
    Redis::shouldReceive('connection')->with('cache')->andReturn($dead);

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: redis [cache] unreachable');
});

it('throws when horizon is inactive', function (): void {
    $this->mock(MasterSupervisorRepository::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([]);
    });

    // Redis runs before Horizon in the check order — mock it healthy so we
    // reach the Horizon assertion. (Redis is not available in CI.)
    Redis::shouldReceive('connection')->with('default')->andReturn(healthyProbeRedis());
    Redis::shouldReceive('connection')->with('cache')->andReturn(healthyProbeRedis());

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: horizon inactive');
});

it('throws when horizon is paused', function (): void {
    $this->mock(MasterSupervisorRepository::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([(object) ['status' => 'paused']]);
    });

    // Same Redis mock as above — horizon check is after Redis.
    Redis::shouldReceive('connection')->with('default')->andReturn(healthyProbeRedis());
    Redis::shouldReceive('connection')->with('cache')->andReturn(healthyProbeRedis());

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: horizon paused');
});
