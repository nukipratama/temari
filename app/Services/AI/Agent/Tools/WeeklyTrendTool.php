<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The last twelve weeks as a series, for the narrators that read a trend rather
 * than a single period.
 */
final class WeeklyTrendTool extends UserTool
{
    private const int WEEKS = 12;

    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly TrainingLoad $trainingLoad,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_weekly_trend';
    }

    public function description(): string
    {
        return 'Dua belas minggu terakhir sebagai deret (km, TRIMP, ctl_42d, atl_7d, form, status, '
            .'dan apakah minggu itu ada PR), plus beban hari ini, perubahan ctl 4 minggu, dan volume '
            .'4 minggu terakhir vs 4 minggu sebelumnya. Kalau ctl_delta_4w atau volume_* gak muncul, '
            .'riwayatnya belum cukup panjang buat dibandingkan, jadi jangan mengarang tren.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $weeks = WeeklySnapshot::query()
            ->where('user_id', $this->user->id)
            ->orderByDesc('week_ending')
            ->limit(self::WEEKS)
            ->get()
            ->reverse()
            ->values();

        [$ctlDelta4w, $volumeRecent, $volumePrev] = self::fourWeekDeltas($weeks);
        $prWeekEndings = $this->prWeekEndings($weeks);

        return [
            'as_of' => $this->asOf->toDateString(),
            'load_today' => $this->trainingLoad->summary($this->user, $this->asOf),
            'ctl_delta_4w' => $ctlDelta4w,
            'volume_recent_4w_km' => $volumeRecent,
            'volume_prev_4w_km' => $volumePrev,
            'weeks' => $weeks->map(fn (WeeklySnapshot $week): array => [
                'ending' => $week->week_ending->toDateString(),
                'distance_km' => $week->distance_km,
                'trimp' => $week->weekly_trimp,
                'ctl_42d' => $week->ctl_42d,
                'atl_7d' => $week->atl_7d,
                'form' => $week->form,
                'status' => $week->form_status,
                'pr' => in_array($week->week_ending->toDateString(), $prWeekEndings, true),
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, WeeklySnapshot>  $weeks
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private static function fourWeekDeltas(Collection $weeks): array
    {
        $ctlDelta = null;
        if ($weeks->count() >= 5) {
            $latestCtl = $weeks->last()?->ctl_42d;
            $priorCtl = $weeks->get($weeks->count() - 5)?->ctl_42d;
            if ($latestCtl !== null && $priorCtl !== null) {
                $ctlDelta = round($latestCtl - $priorCtl, 1);
            }
        }

        $recent = $prev = null;
        if ($weeks->count() >= 8) {
            $recent = round((float) $weeks->slice($weeks->count() - 4, 4)->sum('distance_km'), 1);
            $prev = round((float) $weeks->slice($weeks->count() - 8, 4)->sum('distance_km'), 1);
        }

        return [$ctlDelta, $recent, $prev];
    }

    /**
     * Week-ending dates (Sunday) in which the runner set a personal record, so
     * the caption can honestly cite a "PR week". A PR is bucketed into the week
     * its `set_at` falls in.
     *
     * @param  Collection<int, WeeklySnapshot>  $weeks
     * @return list<string>
     */
    private function prWeekEndings(Collection $weeks): array
    {
        if ($weeks->isEmpty()) {
            return [];
        }

        $endings = PersonalRecord::query()
            ->where('user_id', $this->user->id)
            ->whereNotNull('set_at')
            ->whereBetween('set_at', [
                $weeks->first()->week_ending->copy()->subDays(6)->startOfDay(),
                $weeks->last()->week_ending->copy()->endOfDay(),
            ])
            ->get()
            ->map(fn (PersonalRecord $record): string => $record->set_at->copy()->endOfWeek(Carbon::SUNDAY)->toDateString())
            ->unique()
            ->all();

        return array_values($endings);
    }
}
