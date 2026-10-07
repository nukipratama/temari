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

it('keeps neighbouring slots apart at the highest worker token', function () {
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

it('reads integer Redis settings', function (mixed $value, int $expected) {
    expect(TestCase::integerSetting('REDIS_DB', $value))->toBe($expected);
})->with([['32', 32], [0, 0], ['0', 0]]);

it('refuses a non-integer Redis setting instead of coercing it', function (mixed $value) {
    TestCase::integerSetting('REDIS_DB', $value);
})->with(['', 'abc', '32abc', null])->throws(RuntimeException::class, 'REDIS_DB must be an integer');

it('accepts the base that matches the worktree slot file, or no slot file', function (int $base, ?string $slotFile) {
    TestCase::assertSlotBase($base, $slotFile);
})->with([[0, null], [32, "1\n"], [96, '3']])->throwsNoExceptions();

it('refuses a base left over from another slot', function () {
    TestCase::assertSlotBase(32, "2\n");
})->throws(RuntimeException::class, 'REDIS_DB 32 does not match worktree slot 2, which expects 64');

it('allows only the test Redis hosts', function (string $host) {
    TestCase::assertTestRedisHost($host);
})->with(['redis_test', 'temari-shared-redis-test', '127.0.0.1'])->throwsNoExceptions();

it('refuses the dev Redis host', function (string $host) {
    TestCase::assertTestRedisHost($host);
})->with(['redis', 'temari-shared-redis', ''])->throws(RuntimeException::class, 'Refusing to flush Redis host');
