<?php

declare(strict_types=1);

namespace App\Support\Config;

use Illuminate\Support\Facades\DB;

/**
 * Read-through accessor for the durable runtime control plane (`app_config`).
 *
 * Bound `scoped`, so the per-request/per-job memo collapses repeat reads to one
 * query and is flushed between requests (Octane) and queue jobs — keeping the DB
 * the single source of truth without a Redis layer that LRU eviction could lose.
 */
class AppConfig
{
    private const string TABLE = 'app_config';

    /** @var array<string, mixed> */
    private array $memo = [];

    public function get(AppConfigKey $key): mixed
    {
        if (array_key_exists($key->value, $this->memo)) {
            return $this->memo[$key->value];
        }

        $stored = DB::table(self::TABLE)->where('key', $key->value)->value('value');

        $resolved = $stored === null
            ? $key->default()
            : $key->cast(json_decode((string) $stored, true));

        return $this->memo[$key->value] = $resolved;
    }

    public function boolean(AppConfigKey $key): bool
    {
        return (bool) $this->get($key);
    }

    public function integer(AppConfigKey $key): int
    {
        return (int) $this->get($key);
    }

    /**
     * Drop a key from the per-request memo so the next get() re-reads from the DB.
     * Used by the circuit breaker to read fresh counter state under its lock.
     */
    public function forget(AppConfigKey $key): void
    {
        unset($this->memo[$key->value]);
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
            $this->memo[$key->value] = $cast;
        }

        DB::table(self::TABLE)->upsert($rows, ['key'], ['value', 'updated_at']);
    }
}
