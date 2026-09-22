<?php

declare(strict_types=1);

namespace App\Services\Run\Trend;

use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class ScheduledTrendSnapshotRecovery
{
    public const int CHUNK_DAYS = 365;

    public function __construct(private TrendSnapshotWriter $writer)
    {
    }

    public function recover(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $locked = User::query()->lockForUpdate()->find($user->id);
            if ($locked === null) {
                return true;
            }

            $latest = Carbon::today()->startOfDay()->subDay();
            $cursor = $locked->trend_snapshots_scheduled_through;

            if ($cursor === null) {
                $anchor = $this->anchorFor($locked);
                $cursor = $anchor->subDay();
            }

            if ($cursor->gte($latest)) {
                $locked->forceFill(['trend_snapshots_scheduled_through' => $latest])->saveQuietly();

                return true;
            }

            $from = $cursor->copy()->addDay();
            $through = $from->copy()->addDays(self::CHUNK_DAYS - 1);
            if ($through->gt($latest)) {
                $through = $latest;
            }

            $this->writer->writeRange($locked, $from, $through);
            $locked->forceFill(['trend_snapshots_scheduled_through' => $through])->saveQuietly();

            return $through->gte($latest);
        });
    }

    private function anchorFor(User $user): Carbon
    {
        $dates = [
            TrendDailySnapshot::query()->where('user_id', $user->id)->min('snapshot_date'),
            ActivityDetail::query()->forUser($user->id)->min('start_date_local'),
            $user->created_at?->toDateString(),
        ];

        return collect($dates)
            ->filter()
            ->map(fn (string $date): Carbon => Carbon::parse($date)->startOfDay())
            ->sort()
            ->first() ?? Carbon::today();
    }
}
