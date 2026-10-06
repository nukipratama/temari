<?php

use Tests\TestCase;

it('never shares a Redis database between any slot and worker', function () {
    $owners = [];

    foreach (range(0, 3) as $slot) {
        foreach (range(0, 15) as $token) {
            foreach (TestCase::redisDatabases($slot * 32, $token) as $database) {
                expect($database)->toBeGreaterThanOrEqual(0)->toBeLessThan(256);
                expect($owners)->not->toHaveKey($database);
                $owners[$database] = "{$slot}:{$token}";
            }
        }
    }

    expect($owners)->toHaveCount(128);
});

it('keeps slots 1 and 2 and slots 0 and 3 apart at the highest worker token', function () {
    expect(TestCase::redisDatabases(32, 15))->toBe([62, 63])
        ->and(TestCase::redisDatabases(64, 0))->toBe([64, 65])
        ->and(TestCase::redisDatabases(0, 15))->toBe([30, 31])
        ->and(TestCase::redisDatabases(96, 0))->toBe([96, 97]);
});

it('refuses a worker token beyond the slot capacity', function () {
    TestCase::redisDatabases(0, 16);
})->throws(RuntimeException::class, 'TEST_TOKEN 16 is out of range: at most 15 parallel workers');

it('refuses a slot above 3 or a base that is not a slot base', function (int $base) {
    TestCase::redisDatabases($base, 0);
})->with([128, 33, -32])->throws(RuntimeException::class, 'not a slot base');

it('allows only the test Redis hosts', function (string $host) {
    TestCase::assertTestRedisHost($host);
    expect(true)->toBeTrue();
})->with(['redis_test', 'temari-shared-redis-test', '127.0.0.1']);

it('refuses the dev Redis host', function (string $host) {
    TestCase::assertTestRedisHost($host);
})->with(['redis', 'temari-shared-redis', ''])->throws(RuntimeException::class, 'Refusing to flush Redis host');
