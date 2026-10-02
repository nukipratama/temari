<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Remembers which exception fingerprints have been seen in the last
 * {@see self::SEEN_DAYS} days and queues every never-seen one for the next
 * maintainer digest ({@see \App\Console\Commands\ExceptionDigestCommand}).
 *
 * A fingerprint is built from where an error happened, never from what it
 * said: the digest carries no exception message, user id or request URL.
 */
final class NewExceptionLedger
{
    public const int SEEN_DAYS = 30;

    public const int MAX_PENDING = 100;

    private const string PENDING_KEY = 'ops.exceptions.pending';

    private const string LOCK_KEY = 'ops.exceptions.lock';

    public static function recordServer(Throwable $exception): void
    {
        $where = Str::after($exception->getFile(), base_path().DIRECTORY_SEPARATOR).':'.$exception->getLine();
        $label = $exception::class.' at '.$where;

        self::record(sha1($label), $label);
    }

    public static function recordBrowser(string $message, ?string $stack): void
    {
        $frame = self::firstFrame($stack) ?? 'unknown frame';
        $fingerprint = sha1($message."\n".$frame);

        self::record($fingerprint, 'browser error at '.$frame.' (#'.substr($fingerprint, 0, 8).')');
    }

    /**
     * Every fingerprint queued since the last call, oldest first, and an empty
     * queue behind it.
     *
     * @return list<array{label: string, first_seen: string, count: int}>
     */
    public static function pull(): array
    {
        return self::locked(function (): array {
            $pending = self::pending();
            Cache::forget(self::PENDING_KEY);

            return array_values($pending);
        });
    }

    private static function record(string $fingerprint, string $label): void
    {
        try {
            self::locked(function () use ($fingerprint, $label): void {
                $pending = self::pending();

                if (isset($pending[$fingerprint])) {
                    $pending[$fingerprint]['count']++;
                } elseif (count($pending) < self::MAX_PENDING && Cache::add(self::seenKey($fingerprint), true, Carbon::now()->addDays(self::SEEN_DAYS))) {
                    $pending[$fingerprint] = ['label' => $label, 'first_seen' => Carbon::now()->toIso8601String(), 'count' => 1];
                } else {
                    return;
                }

                Cache::put(self::PENDING_KEY, $pending, Carbon::now()->addDays(self::SEEN_DAYS));
            });
        } catch (Throwable) {
            return;
        }
    }

    /**
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    private static function locked(callable $work): mixed
    {
        try {
            return Cache::lock(self::LOCK_KEY, 10)->block(3, $work);
        } catch (LockTimeoutException) {
            return $work();
        }
    }

    /**
     * @return array<string, array{label: string, first_seen: string, count: int}>
     */
    private static function pending(): array
    {
        $pending = Cache::get(self::PENDING_KEY);

        return is_array($pending) ? $pending : [];
    }

    private static function seenKey(string $fingerprint): string
    {
        return 'ops.exceptions.seen:'.$fingerprint;
    }

    /** The first `path:line:column` in a browser stack, with its origin dropped and numeric path segments masked. */
    private static function firstFrame(?string $stack): ?string
    {
        if ($stack === null || preg_match('~(?:[a-z][a-z0-9+.-]*://[^/\s)]+)?(/[^\s()?#]+)(?:[?#][^\s():]*)?(:\d+:\d+)~i', $stack, $match) !== 1) {
            return null;
        }

        return preg_replace('~/\d+(?=/|$)~', '/{id}', $match[1]).$match[2];
    }
}
