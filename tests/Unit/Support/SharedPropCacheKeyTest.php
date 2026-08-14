<?php

declare(strict_types=1);

use App\Support\SharedPropCacheKey;
use Illuminate\Support\Facades\Cache;

it('suffixes per-user keys with the user id', function (SharedPropCacheKey $case): void {
    expect($case->key(7))->toBe($case->value.':7');
})->with(array_filter(
    SharedPropCacheKey::cases(),
    fn (SharedPropCacheKey $case): bool => ! in_array($case, [SharedPropCacheKey::AiPaused, SharedPropCacheKey::StravaPaused], true),
));

it('keeps the global pause signals un-suffixed even when handed a user id', function (): void {
    expect(SharedPropCacheKey::AiPaused->key())->toBe('ai-paused')
        ->and(SharedPropCacheKey::AiPaused->key(7))->toBe('ai-paused')
        ->and(SharedPropCacheKey::StravaPaused->key())->toBe('strava-paused')
        ->and(SharedPropCacheKey::StravaPaused->key(7))->toBe('strava-paused');
});

it('gives every key a positive TTL', function (SharedPropCacheKey $case): void {
    expect($case->ttl())->toBeGreaterThan(0);
})->with(SharedPropCacheKey::cases());

it('keeps the documented TTLs', function (): void {
    expect(SharedPropCacheKey::AiPaused->ttl())->toBe(60)
        ->and(SharedPropCacheKey::StravaPaused->ttl())->toBe(60)
        ->and(SharedPropCacheKey::StravaSync->ttl())->toBe(120)
        ->and(SharedPropCacheKey::HrZonesChangedAt->ttl())->toBe(300)
        ->and(SharedPropCacheKey::EquippedAccessories->ttl())->toBe(300)
        ->and(SharedPropCacheKey::TelegramConnected->ttl())->toBe(300)
        ->and(SharedPropCacheKey::WebPushSubscribed->ttl())->toBe(300)
        ->and(SharedPropCacheKey::StravaZoneScopeMissing->ttl())->toBe(300);
});

it('never collides two cases onto the same key for the same user', function (): void {
    $keys = array_map(fn (SharedPropCacheKey $case): string => $case->key(1), SharedPropCacheKey::cases());

    expect(array_unique($keys))->toHaveCount(count($keys));
});

it('computes once and serves the cached value afterwards', function (): void {
    $calls = 0;
    $compute = function () use (&$calls): string {
        $calls++;

        return 'value';
    };

    expect(SharedPropCacheKey::TelegramConnected->remember(1, $compute))->toBe('value')
        ->and(SharedPropCacheKey::TelegramConnected->remember(1, $compute))->toBe('value')
        ->and($calls)->toBe(1);
});

it('caches a false value rather than treating it as a miss', function (): void {
    $calls = 0;
    $compute = function () use (&$calls): bool {
        $calls++;

        return false;
    };

    SharedPropCacheKey::WebPushSubscribed->remember(1, $compute);
    SharedPropCacheKey::WebPushSubscribed->remember(1, $compute);

    expect($calls)->toBe(1);
});

it('keeps one user out of another user cached value', function (): void {
    SharedPropCacheKey::TelegramConnected->remember(1, fn (): bool => true);
    SharedPropCacheKey::TelegramConnected->remember(2, fn (): bool => false);

    expect(SharedPropCacheKey::TelegramConnected->remember(1, fn (): bool => false))->toBeTrue()
        ->and(SharedPropCacheKey::TelegramConnected->remember(2, fn (): bool => true))->toBeFalse();
});

it('recomputes after a forget', function (): void {
    $calls = 0;
    $compute = function () use (&$calls): int {
        $calls++;

        return $calls;
    };

    SharedPropCacheKey::EquippedAccessories->remember(1, $compute);
    SharedPropCacheKey::EquippedAccessories->forget(1);
    SharedPropCacheKey::EquippedAccessories->remember(1, $compute);

    expect($calls)->toBe(2);
});

it('forgets only the targeted user', function (): void {
    SharedPropCacheKey::EquippedAccessories->remember(1, fn (): string => 'one');
    SharedPropCacheKey::EquippedAccessories->remember(2, fn (): string => 'two');

    SharedPropCacheKey::EquippedAccessories->forget(1);

    expect(Cache::has(SharedPropCacheKey::EquippedAccessories->key(1)))->toBeFalse()
        ->and(SharedPropCacheKey::EquippedAccessories->remember(2, fn (): string => 'recomputed'))->toBe('two');
});

it('hands an int back as an int over redis, which stores numerics unserialized', function (): void {
    config()->set('cache.default', 'redis');
    Cache::purge();

    Cache::put('shared-prop-store-probe', 5, 60);
    expect(Cache::get('shared-prop-store-probe'))->toBe('5');
    Cache::forget('shared-prop-store-probe');

    SharedPropCacheKey::UnreadNotifications->forget(1);

    expect(SharedPropCacheKey::UnreadNotifications->remember(1, fn (): int => 5))->toBe(5)
        ->and(SharedPropCacheKey::UnreadNotifications->remember(1, fn (): int => 99))->toBe(5);

    SharedPropCacheKey::UnreadNotifications->forget(1);
});
