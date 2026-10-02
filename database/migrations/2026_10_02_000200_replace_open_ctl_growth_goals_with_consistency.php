<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        $seasons = DB::table('seasons')
            ->join('season_goals', 'season_goals.season_id', '=', 'seasons.id')
            ->whereNull('seasons.race_goal_id')
            ->where('seasons.ends_at', '>=', Carbon::today()->toDateString())
            ->where('season_goals.metric', 'season_ctl_growth')
            ->get(['season_goals.id', 'seasons.starts_at', 'seasons.ends_at']);

        foreach ($seasons as $row) {
            $endsAt = Carbon::parse($row->ends_at);
            $weeks = 0;
            for ($sunday = Carbon::parse($row->starts_at)->endOfWeek(Carbon::SUNDAY)->startOfDay(); $sunday->lte($endsAt); $sunday->addWeek()) {
                $weeks++;
            }

            DB::table('season_goals')->where('id', $row->id)->update([
                'title' => 'Run your planned volume week by week',
                'metric' => 'season_consistent_weeks',
                'target' => max(1, $weeks),
                'unit' => 'weeks',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('season_goals')->where('metric', 'season_consistent_weeks')->update([
            'title' => 'Grow your fitness (CTL) this season',
            'metric' => 'season_ctl_growth',
            'target' => 3.0,
            'unit' => 'CTL pts',
        ]);
    }
};
