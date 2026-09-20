<?php

declare(strict_types=1);

namespace App\Support\Config;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cached accessor for the durable runtime control plane (`app_config`).
 */
class AppConfig
{
    private const string TABLE = 'app_config';

    private const string ENVELOPE = '__config';

    public const int CACHE_TTL_SECONDS = 60;

    public function get(AppConfigKey $key): mixed
    {
        try {
            $cached = Cache::get($key->cacheKey());

            if (is_array($cached) && array_key_exists(self::ENVELOPE, $cached)) {
                return $key->cast($cached[self::ENVELOPE]);
            }
        } catch (Throwable $e) {
            $this->logCacheFailure('read', $key, $e);
        }

        $stored = DB::table(self::TABLE)->where('key', $key->value)->value('value');

        $resolved = $stored === null
            ? $key->default()
            : $key->cast(json_decode((string) $stored, true));

        $this->cache($key, $resolved);

        return $resolved;
    }

    public function boolean(AppConfigKey $key): bool
    {
        return (bool) $this->get($key);
    }

    public function integer(AppConfigKey $key): int
    {
        return (int) $this->get($key);
    }

    public function forget(AppConfigKey $key): void
    {
        try {
            Cache::forget($key->cacheKey());
        } catch (Throwable $e) {
            $this->logCacheFailure('forget', $key, $e);
        }
    }

    public function set(AppConfigKey $key, mixed $value): void
    {
        $this->setMany([[$key, $value]]);
    }

    /**
     * Upsert several keys in a single write — used for atomic multi-key state
     * such as the circuit breaker (state + failures + opened_at change together).
     *
     * @param  list<array{0: AppConfigKey, 1: mixed}>  $pairs
     */
    public function setMany(array $pairs): void
    {
        $now = now();
        $rows = [];

        foreach ($pairs as [$key, $value]) {
            $cast = $key->cast($value);
            $rows[] = [
                'key' => $key->value,
                'value' => json_encode($cast),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table(self::TABLE)->upsert($rows, ['key'], ['value', 'updated_at']);

        foreach ($pairs as [$key, $value]) {
            $this->cache($key, $key->cast($value));
        }
    }

    private function cache(AppConfigKey $key, mixed $value): void
    {
        try {
            Cache::put($key->cacheKey(), [self::ENVELOPE => $value], self::CACHE_TTL_SECONDS);
        } catch (Throwable $e) {
            $this->logCacheFailure('write', $key, $e);
        }
    }

    private function logCacheFailure(string $operation, AppConfigKey $key, Throwable $e): void
    {
        Log::warning('app_config.cache_unavailable', [
            'operation' => $operation,
            'key' => $key->value,
            'reason' => $e->getMessage(),
        ]);
    }
}
