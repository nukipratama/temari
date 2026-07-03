<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Gamification\UnlockEngine;
use App\Services\Run\Metrics\StreamSummary;
use Illuminate\Support\Carbon;

class RunCardFactory
{
    private const int LONG_SLOW_DISTANCE_THRESHOLD_M = 12_000;

    private const int LONG_SLOW_DISTANCE_DURATION_S = 3_600;

    private const int PACE_KILAT_SEC_PER_KM = 300;

    private const int ELEVATION_GAIN_M = 200;

    private const int CONSECUTIVE_DAYS_RAJIN = 3;

    private const int CONSECUTIVE_DAYS_BERTURUT = 7;

    private const int WEEKLY_CONSISTENCY_RUNS = 3;

    /** Distance brackets (metres) for first-bracket tracking. */
    private const array DISTANCE_BRACKETS = [
        5_000,
        10_000,
        15_000,
        21_097.5,
        42_195.0,
    ];

    /**
     * Indonesian national holidays (month-day). Covers fixed-date public
     * holidays; Easter-based ones are excluded because they shift each year.
     */
    private const array INDONESIAN_HOLIDAYS_MD = [
        '01-01', // Tahun Baru
        '01-29', // Tahun Baru Imlek (2025 approx; fixed 01-29 for simplicity)
        '03-31', // Hari Nyepi (approx; fixed for simplicity)
        '05-01', // Hari Buruh
        '05-20', // Hari Kebangkitan Nasional
        '06-01', // Hari Lahir Pancasila
        '08-17', // Hari Kemerdekaan
        '10-01', // Hari Kesaktian Pancasila
        '12-25', // Natal
    ];

    public function __construct(
        private readonly SpecialMoves $specialMoves,
        private readonly AnalysisService $analysisService,
        private readonly UnlockEngine $unlockEngine,
    ) {
    }

    public function build(Activity $activity, ActivityDetail $detail): RunCard
    {
        $summary = $detail->streamSummary();
        $prSet = $this->hasPrFromThisActivity($activity);

        // Badges compute first so rarity can derive from badge count.
        $badges = $this->badges($activity, $detail, $summary);
        $rarity = $this->rarityFromScore(
            $this->rarityScore($activity, $detail, $summary, $badges, $prSet),
        );

        $move = $this->specialMoves->pick($summary, [
            'distance_m' => $detail->distance,
            'pr_set' => $prSet,
            'seed' => $activity->id,
        ]);

        $existing = RunCard::query()->where('activity_id', $activity->id)->first();
        $previousRarityRank = $existing?->rarity->rank() ?? -1;

        $card = RunCard::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'rarity' => $rarity,
                'badges' => $badges,
                'special_move' => $move,
            ],
        );

        $this->analysisService->request(
            subjectOrType: RunCard::class,
            subjectId: $card->id,
            type: AnalysisType::CardFlavor,
            invalidate: true,
        );

        if (in_array($card->rarity, [Rarity::Epic, Rarity::Legendary], strict: true)) {
            $this->unlockEngine->grantEligible($activity->user);
        }

        if ($card->rarity->rank() > $previousRarityRank) {
            $this->queueRevealFor($activity, $card);
        }

        return $card;
    }

    /**
     * Compute the rarity score from point sources.
     *
     * Point sources:
     *  +3 PR set
     *  +2 negative split
     *  +2 long run (>=12km)
     *  +1 first distance bracket
     *  +1 per badge earned
     *  +1 zone discipline (<10% Z3+ on >=10km)
     *  +1 weekly consistency (>=3 runs this week)
     *
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $badges
     */
    public function rarityScore(
        Activity $activity,
        ActivityDetail $detail,
        array $summary,
        array $badges,
        bool $prSet,
    ): int {
        $score = 0;
        $distance = (float) ($detail->distance ?? 0);
        $negativeSplit = ($summary['negative_split'] ?? false) === true;

        if ($prSet) {
            $score += 3;
        }
        if ($negativeSplit) {
            $score += 2;
        }
        if ($distance >= self::LONG_SLOW_DISTANCE_THRESHOLD_M) {
            $score += 2;
        }
        if ($this->isFirstDistanceBracket($activity, $detail)) {
            $score += 1;
        }
        $score += count($badges);
        if ($this->isAerobicDiscipline($detail, $summary)) {
            $score += 1;
        }
        if ($this->weeklyConsistency($activity)) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Map a point total to a rarity tier.
     *
     * Tiers: 0-1 Biasa, 2-3 Berkesan, 4-5 Langka, 6-7 Istimewa, 8+ Legendaris
     */
    public function rarityFromScore(int $score): Rarity
    {
        return match (true) {
            $score >= 8 => Rarity::Legendary,
            $score >= 6 => Rarity::Epic,
            $score >= 4 => Rarity::Rare,
            $score >= 2 => Rarity::Uncommon,
            default => Rarity::Common,
        };
    }

    /**
     * Whether this is the user's first run crossing one of the standard
     * distance brackets (5K / 10K / 15K / 21K / 42K).
     */
    public function isFirstDistanceBracket(Activity $activity, ActivityDetail $detail): bool
    {
        $distance = (float) ($detail->distance ?? 0);
        if ($distance <= 0) {
            return false;
        }

        $reachedBracket = $this->highestReachedBracket($distance);

        if ($reachedBracket === null) {
            return false;
        }

        $previousAtBracket = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->where('activities.id', '!=', $activity->id)
            ->where('activity_details.distance', '>=', $reachedBracket)
            ->exists();

        return ! $previousAtBracket;
    }

    /**
     * Whether the user has >=3 runs in the same ISO week as this activity.
     */
    public function weeklyConsistency(Activity $activity): bool
    {
        $startDate = $activity->detail?->start_date_local;

        if ($startDate === null) {
            return false;
        }

        $weekStart = $startDate->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $startDate->copy()->endOfWeek(Carbon::SUNDAY);

        $runCount = Activity::query()
            ->where('user_id', $activity->user_id)
            ->whereHas('detail', function ($q) use ($weekStart, $weekEnd): void {
                $q->whereBetween('start_date_local', [$weekStart, $weekEnd]);
            })
            ->count();

        return $runCount >= self::WEEKLY_CONSISTENCY_RUNS;
    }

    /**
     * Stash the card id on the user so the next page load can pop the reveal
     * modal. Only one reveal can be pending at a time.
     */
    private function queueRevealFor(Activity $activity, RunCard $card): void
    {
        $user = $activity->user;
        if ($user->pending_reveal_card_id !== null) {
            return;
        }
        $user->forceFill(['pending_reveal_card_id' => $card->id])->save();
    }

    /**
     * Compute all badges for a run. Split into original + expanded badge groups
     * to keep cognitive complexity manageable.
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function badges(Activity $activity, ActivityDetail $detail, array $summary): array
    {
        $badges = $this->originalBadges($detail, $summary);
        $streak = $this->consecutiveDaysBefore($activity);

        return array_merge($badges, $this->expandedBadges($activity, $detail, $summary, $streak));
    }

    /**
     * Original 6 badges: weather, time-of-day, distance, split, discipline.
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function originalBadges(ActivityDetail $detail, array $summary): array
    {
        $badges = [];

        if (($detail->weather_temp_c ?? 0) >= 31) {
            $badges[] = Badge::HariPanas->value;
        }
        if ($detail->weather_rain_detected === true) {
            $badges[] = Badge::PejuangHujan->value;
        }
        if ($detail->start_date_local !== null && (int) $detail->start_date_local->format('H') < 6) {
            $badges[] = Badge::AnakPagi->value;
        }
        if ($this->isLongSlowDistance($detail, $summary)) {
            $badges[] = Badge::LongSlowDistance->value;
        }
        if (($summary['negative_split'] ?? false) === true) {
            $badges[] = Badge::NegativeSplit->value;
        }
        if ($this->isAerobicDiscipline($detail, $summary)) {
            $badges[] = Badge::TahanDiri->value;
        }

        return $badges;
    }

    /**
     * 12 expanded badges: night, elevation, first-run, streaks, pace,
     * distance, zones, effort, holiday.
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function expandedBadges(Activity $activity, ActivityDetail $detail, array $summary, int $streak): array
    {
        $badges = [];
        $distance = (float) ($detail->distance ?? 0);
        $hour = $this->startHour($detail);

        if ($hour !== null && ($hour < 5 || $hour >= 21)) {
            $badges[] = Badge::AnakMalam->value;
        }
        if (($detail->total_elevation_gain ?? 0) >= self::ELEVATION_GAIN_M) {
            $badges[] = Badge::Pendaki->value;
        }
        if ($this->isFirstRunEver($activity)) {
            $badges[] = Badge::PertamaKali->value;
        }
        if ($streak + 1 >= self::CONSECUTIVE_DAYS_RAJIN) {
            $badges[] = Badge::Rajin->value;
        }

        $paceSec = $detail->paceSecPerKm();
        if ($paceSec !== null && $paceSec < self::PACE_KILAT_SEC_PER_KM) {
            $badges[] = Badge::Kilat->value;
        }
        if ($distance >= 21_097.5) {
            $badges[] = Badge::Jauh->value;
        }

        $badges = array_merge($badges, $this->zoneAndEffortBadges($activity, $detail, $summary, $hour));

        if ($streak + 1 >= self::CONSECUTIVE_DAYS_BERTURUT) {
            $badges[] = Badge::Berturut->value;
        }
        if ($this->isIndonesianHoliday($detail)) {
            $badges[] = Badge::HariSpesial->value;
        }

        return $badges;
    }

    /**
     * Zone-based and effort-based badges: Z2 Master, Anak Dingin, Keras, Santai.
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function zoneAndEffortBadges(Activity $activity, ActivityDetail $detail, array $summary, ?int $hour): array
    {
        $badges = [];

        $zonePct = StreamSummary::zonePct($summary);
        if (($zonePct['Z2'] ?? 0) > 80.0) {
            $badges[] = Badge::Z2Master->value;
        }
        if ($hour !== null && $hour < 6) {
            $badges[] = Badge::AnakDingin->value;
        }
        if ($this->isHardEffort($activity, $detail)) {
            $badges[] = Badge::Keras->value;
        }
        if ($this->isEasyEffort($activity, $detail)) {
            $badges[] = Badge::Santai->value;
        }

        return $badges;
    }

    /**
     * Extract the start-hour (0-23) from the detail's start_date_local, or null.
     */
    private function startHour(ActivityDetail $detail): ?int
    {
        return $detail->start_date_local !== null
            ? (int) $detail->start_date_local->format('H')
            : null;
    }

    /**
     * Whether this is the user's first ingested activity. The AnalyzedScope
     * excludes un-analyzed stubs, so a sync backlog cannot suppress the
     * "PertamaKali" badge on the real first run.
     */
    private function isFirstRunEver(Activity $activity): bool
    {
        return Activity::query()
            ->where('user_id', $activity->user_id)
            ->where('id', '!=', $activity->id)
            ->doesntExist();
    }

    /**
     * Count consecutive running days ending the day before this activity.
     * Returns the streak length (0 = no run yesterday).
     */
    private function consecutiveDaysBefore(Activity $activity): int
    {
        $startDate = $activity->detail?->start_date_local;

        if ($startDate === null) {
            return 0;
        }

        // Fetch the last 30 distinct run dates in one query, then count
        // consecutive days in PHP. Much cheaper than N queries for long streaks.
        $dates = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->whereDate('start_date_local', '<', $startDate->toDateString())
            ->selectRaw('DISTINCT DATE(start_date_local) as run_date')
            ->orderByDesc('run_date')
            ->limit(30)
            ->pluck('run_date')
            ->map(fn (string $d): string => Carbon::parse($d)->toDateString())
            ->flip();

        $streak = 0;
        $checkDate = $startDate->copy()->subDay();

        while (isset($dates[$checkDate->toDateString()])) {
            $streak++;
            $checkDate->subDay();
        }

        return $streak;
    }

    /**
     * Whether the run's start_date_local falls on an Indonesian national holiday.
     */
    private function isIndonesianHoliday(ActivityDetail $detail): bool
    {
        $startDate = $detail->start_date_local;

        if ($startDate === null) {
            return false;
        }

        $md = $startDate->format('m-d');

        return in_array($md, self::INDONESIAN_HOLIDAYS_MD, strict: true);
    }

    /**
     * Hard effort: average HR > 85% of the athlete's max HR.
     */
    private function isHardEffort(Activity $activity, ActivityDetail $detail): bool
    {
        $ratio = $this->hrRatio($activity, $detail);

        return $ratio !== null && $ratio > 0.85;
    }

    /**
     * Easy effort: average HR < 70% of the athlete's max HR.
     */
    private function isEasyEffort(Activity $activity, ActivityDetail $detail): bool
    {
        $ratio = $this->hrRatio($activity, $detail);

        return $ratio !== null && $ratio < 0.70;
    }

    /**
     * Average HR as a fraction of the athlete's true max HR, taken from the
     * user's hrProfile rather than this run's own peak HR. Null when avg HR is
     * missing. The athlete max falls back to the hrProfile default, which is
     * never zero, so the denominator is always positive.
     */
    private function hrRatio(Activity $activity, ActivityDetail $detail): ?float
    {
        $avg = $detail->average_heartrate;

        if ($avg === null) {
            return null;
        }

        $athleteMaxHr = $activity->user->hrProfile()['max_hr'];

        if ($athleteMaxHr <= 0) {
            return null;
        }

        return $avg / $athleteMaxHr;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function isLongSlowDistance(ActivityDetail $detail, array $summary): bool
    {
        $distance = $detail->distance ?? 0;
        $elapsed = $detail->elapsed_time ?? 0;
        if ($distance < self::LONG_SLOW_DISTANCE_THRESHOLD_M || $elapsed < self::LONG_SLOW_DISTANCE_DURATION_S) {
            return false;
        }

        return StreamSummary::hardZoneShare($summary) < 25.0;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function isAerobicDiscipline(ActivityDetail $detail, array $summary): bool
    {
        $distance = $detail->distance ?? 0;
        if ($distance < 10_000) {
            return false;
        }

        return StreamSummary::hardZoneShare($summary) < 10.0;
    }

    private function hasPrFromThisActivity(Activity $activity): bool
    {
        return PersonalRecord::query()
            ->where('activity_id', $activity->id)
            ->exists();
    }

    /**
     * Find the highest distance bracket reached by a run distance.
     * Returns null when the distance doesn't reach any bracket.
     */
    private function highestReachedBracket(float $distance): ?float
    {
        $reached = null;
        foreach (self::DISTANCE_BRACKETS as $bracket) {
            if ($distance >= $bracket) {
                $reached = $bracket;
            }
        }

        return $reached;
    }
}
