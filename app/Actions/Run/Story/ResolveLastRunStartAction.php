<?php

declare(strict_types=1);

namespace App\Actions\Run\Story;

use App\Models\ActivityDetail;
use Illuminate\Support\Carbon;

/**
 * The athlete's most recent run start, read once per request.
 *
 * Bound `scoped()` in AppServiceProvider — {@see \App\Services\Run\Story\Vibe}
 * and {@see \App\Services\Run\Story\RecoveryWindow} ask the same question from
 * three unrelated places on one Home render, and the memo can only collapse
 * them if they share an instance.
 *
 * A ceiling keeps the window as-of the caller's date, so a backdated recompute
 * (self-heal / dead-letter retry) never sees a later run. It costs a second
 * read only when the athlete's latest run is genuinely past the ceiling, which
 * on a live page never happens. {@see ActivityDetail::booted()} and
 * {@see \App\Models\Activity::booted()} drop the memo on any write.
 */
class ResolveLastRunStartAction
{
    /** @var array<int, string|null> */
    private array $latest = [];

    /** @var array<string, string|null> */
    private array $bounded = [];

    public function __invoke(int $userId, ?Carbon $ceiling = null): ?Carbon
    {
        if (! array_key_exists($userId, $this->latest)) {
            $this->latest[$userId] = $this->read($userId, null);
        }

        $latest = $this->latest[$userId];

        if ($latest === null) {
            return null;
        }

        if ($ceiling === null || $latest <= $ceiling->toDateTimeString()) {
            return Carbon::parse($latest);
        }

        $key = $userId.'|'.$ceiling->toDateTimeString();
        if (! array_key_exists($key, $this->bounded)) {
            $this->bounded[$key] = $this->read($userId, $ceiling);
        }

        $bounded = $this->bounded[$key];

        return $bounded === null ? null : Carbon::parse($bounded);
    }

    public function flush(): void
    {
        $this->latest = [];
        $this->bounded = [];
    }

    private function read(int $userId, ?Carbon $ceiling): ?string
    {
        /** @var string|Carbon|null $value */
        $value = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('start_date_local')
            ->when($ceiling !== null, fn ($q) => $q->where('start_date_local', '<=', $ceiling))
            ->orderByDesc('start_date_local')
            ->value('start_date_local');

        return $value === null ? null : Carbon::parse($value)->toDateTimeString();
    }
}
