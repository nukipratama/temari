<?php

declare(strict_types=1);

use App\Listeners\VerifyDependencies;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('passes when mysql and both redis connections respond', function (): void {
    // Real test-stack MySQL + Redis are up, so handle() should not throw.
    (new VerifyDependencies())->handle(new DiagnosingHealth());

    expect(true)->toBeTrue();
});

it('throws when mysql is unreachable', function (): void {
    DB::shouldReceive('connection')->andThrow(new RuntimeException('down'));

    expect(fn () => (new VerifyDependencies())->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: mysql unreachable');
});

it('throws naming the redis connection that is unreachable', function (): void {
    $dead = Mockery::mock();
    $dead->shouldReceive('ping')->andThrow(new RuntimeException('boom'));
    Redis::shouldReceive('connection')->with('default')->andReturn($dead);

    expect(fn () => (new VerifyDependencies())->handle(new DiagnosingHealth()))
        ->toThrow(RuntimeException::class, 'health: redis [default] unreachable');
});
