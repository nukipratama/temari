<?php

declare(strict_types=1);

use App\Support\SharedPropCacheKey;
use Illuminate\Support\Facades\Cache;

it('suffixes per-user keys with the user id', function (SharedPropCacheKey $case): void {
    expect($case->key(7))->toBe($case->value.':7');
})->with(array_filter(
    SharedPropCacheKey::cases(),
    fn (SharedPropCacheKey $case): bool => $case !== SharedPropCacheKey::AiPaused,
));

it('keeps the global ai-paused signal un-suffixed even when handed a user id', function (): void {
    expect(SharedPropCacheKey::AiPaused->key())->toBe('ai-paused')
        ->and(SharedPropCacheKey::AiPaused->key(7))->toBe('ai-paused');
});

it('gives every key a positive TTL', function (SharedPropCacheKey $case): void {
    expect($case->ttl())->toBeGreaterThan(0);
})->with(SharedPropCacheKey::cases());

it('keeps the documented TTLs', function (): void {
    expect(SharedPropCacheKey::AiPaused->ttl())->toBe(60)
        ->and(SharedPropCacheKey::StravaSync->ttl())->toBe(120)
        ->and(SharedPropCacheKey::GoalsSummary->ttl())->toBe(120)
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

    expect(Cache::get(SharedPropCacheKey::TelegramConnected->key(1)))->toBeTrue()
        ->and(Cache::get(SharedPropCacheKey::TelegramConnected->key(2)))->toBeFalse();
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
        ->and(Cache::get(SharedPropCacheKey::EquippedAccessories->key(2)))->toBe('two');
});
