<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The registry for every cached Inertia shared prop: one place owning the key
 * string, the TTL and the bust.
 *
 * Before this, a key was defined in `HandleInertiaRequests` and invalidated from
 * somewhere else entirely — `RunnerProfile` re-typed the string literal and
 * `SystemControl` imported an HTTP middleware constant. Both are the wrong way
 * round: a model and a Pulse card should not depend on the HTTP layer to know
 * how to invalidate their own writes.
 */
enum SharedPropCacheKey: string
{
    case AiCatchingUp = 'ai-catching-up';
    case AiPaused = 'ai-paused';
    case EquippedAccessories = 'equipped-accessories';
    case GoalsSummary = 'goals-summary';
    case HrZonesChangedAt = 'hr-zones-changed-at';
    case StravaPaused = 'strava-paused';
    case StravaSync = 'strava-sync';
    case StravaZoneScopeMissing = 'strava-zone-scope-missing';
    case TelegramConnected = 'telegram-connected';
    case WebPushSubscribed = 'web-push-subscribed';

    /**
     * Short TTL for the Strava-sync share. The two queries it runs fire on
     * every page load, while the "last synced" marker only moves when a sync
     * ingests a new activity (minutes apart at most), so a brief cache trades
     * a tiny staleness window for far fewer per-request queries.
     */
    private const int STRAVA_SYNC_SECONDS = 120;

    /**
     * TTL for the goals summary share. Goals only change when an activity is
     * ingested (minutes apart), so a short cache trades negligible staleness
     * for eliminating ~10 DB queries per page load.
     */
    private const int GOALS_SUMMARY_SECONDS = 120;

    /**
     * TTL for the HR-zones-changed marker. It only moves when the user saves
     * their zones (which busts the cache via {@see \App\Models\RunnerProfile}),
     * so the TTL is just a safety net; the win is skipping a runnerProfile
     * lookup on every page load for the common case of no custom profile.
     */
    private const int HR_ZONES_CHANGED_SECONDS = 300;

    /**
     * TTL for the global AI-pause signal. `generationPaused()` can run a handful
     * of queries (app_config lookup, the config circuit breaker, and the daily
     * cost tally when a ceiling is set), so caching it briefly keeps it off the
     * per-request hot path. It is a single global signal, so the cache key is not
     * per-user, and a 60s staleness window is fine for a soft reassurance banner.
     */
    private const int AI_PAUSED_SECONDS = 60;

    /**
     * TTL for the per-user "narration still catching up" signal. Backfill
     * chains advance one link at a time, so this moves faster than the
     * settings-shaped signals below but still doesn't need to be query-fresh
     * for a soft reassurance banner.
     */
    private const int AI_CATCHING_UP_SECONDS = 120;

    /**
     * TTL for the global Strava-pause signal, which reads the `strava.enabled`
     * kill-switch from the app_config control plane on every page load. A flip
     * on /pulse busts the key, so the TTL is only a safety net.
     */
    private const int STRAVA_PAUSED_SECONDS = 60;

    /**
     * TTL shared by the four settings-shaped signals: equipped accessories,
     * Telegram reachability, web-push reachability and the missing Strava zone
     * scope. Each moves only on an explicit write — an equip, a connect or
     * revoke, a push subscribe or unsubscribe, a notification-preference save —
     * and every one of those paths busts the key, so like the HR-zone marker the
     * TTL is a safety net rather than the mechanism. The win is dropping their
     * per-page-load queries, which is why they get a TTL at all instead of
     * bust-only caching: a missed bust must self-heal in minutes, not never.
     */
    private const int SETTINGS_SIGNAL_SECONDS = 300;

    /**
     * `AiPaused` and `StravaPaused` are single global signals, so they
     * deliberately carry no per-user suffix and ignore `$userId`.
     */
    public function key(?int $userId = null): string
    {
        return match ($this) {
            self::AiPaused, self::StravaPaused => $this->value,
            default => "{$this->value}:{$userId}",
        };
    }

    public function ttl(): int
    {
        return match ($this) {
            self::AiCatchingUp => self::AI_CATCHING_UP_SECONDS,
            self::AiPaused => self::AI_PAUSED_SECONDS,
            self::StravaPaused => self::STRAVA_PAUSED_SECONDS,
            self::GoalsSummary => self::GOALS_SUMMARY_SECONDS,
            self::HrZonesChangedAt => self::HR_ZONES_CHANGED_SECONDS,
            self::StravaSync => self::STRAVA_SYNC_SECONDS,
            self::EquippedAccessories,
            self::StravaZoneScopeMissing,
            self::TelegramConnected,
            self::WebPushSubscribed => self::SETTINGS_SIGNAL_SECONDS,
        };
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public function remember(?int $userId, Closure $compute): mixed
    {
        /** @var TValue */
        return Cache::remember($this->key($userId), $this->ttl(), $compute);
    }

    public function forget(?int $userId = null): void
    {
        Cache::forget($this->key($userId));
    }
}
