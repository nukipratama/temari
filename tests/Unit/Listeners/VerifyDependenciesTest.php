<?php

declare(strict_types=1);

use App\Listeners\VerifyDependencies;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

it('passes when mysql, analytics db, both redis connections, and horizon all respond', function (): void {
    // Horizon workers don't run during Pest, so mock a running master.
    $this->mock(MasterSupervisorRepository::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([(object) ['status' => 'running']]);
    });

    // Redis not available in CI — mock healthy pings.
    $mockRedis = Mockery::mock();
    $mockRedis->shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('default')->andReturn($mockRedis);
    Redis::shouldReceive('connection')->with('cache')->andReturn($mockRedis);

    app(VerifyDependencies::class)->handle(new DiagnosingHealth());

    expect(true)->toBeTrue();
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
    $dead->shouldReceive('ping')->andThrow(new RuntimeException('boom'));
    Redis::shouldReceive('connection')->with('default')->andReturn($dead);

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: redis [default] unreachable');
});

it('throws naming the cache redis connection when default succeeds but cache fails', function (): void {
    // The loop checks 'default' first and stops at the first failure, so this
    // is the only way to reach — and prove — the second iteration.
    $healthy = Mockery::mock();
    $healthy->shouldReceive('ping')->andReturn('PONG');
    $dead = Mockery::mock();
    $dead->shouldReceive('ping')->andThrow(new RuntimeException('boom'));
    Redis::shouldReceive('connection')->with('default')->andReturn($healthy);
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
    $mockRedis = Mockery::mock();
    $mockRedis->shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('default')->andReturn($mockRedis);
    Redis::shouldReceive('connection')->with('cache')->andReturn($mockRedis);

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: horizon inactive');
});

it('throws when horizon is paused', function (): void {
    $this->mock(MasterSupervisorRepository::class, function ($mock): void {
        $mock->shouldReceive('all')->andReturn([(object) ['status' => 'paused']]);
    });

    // Same Redis mock as above — horizon check is after Redis.
    $mockRedis = Mockery::mock();
    $mockRedis->shouldReceive('ping')->andReturn('PONG');
    Redis::shouldReceive('connection')->with('default')->andReturn($mockRedis);
    Redis::shouldReceive('connection')->with('cache')->andReturn($mockRedis);

    expect(fn () => app(VerifyDependencies::class)->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: horizon paused');
});
