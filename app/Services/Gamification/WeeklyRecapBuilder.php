<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;

/**
 * Builds the "Minggu Kamu" weekly recap for the dashboard: km + runs this week
 * vs last week, the best card earned this week, the consecutive-week streak, and
 * the nearest incomplete goal. Reuses the app's Monday-Sunday week boundary
 * ({@see WeeklyAggregator}: week_ending = Sunday) so the recap window lines up
 * with the stored WeeklySnapshot rows.
 */
readonly class WeeklyRecapBuilder
{
    public function __construct(private GoalResolver $goalResolver)
    {
    }

    public function forUser(User $user, ?Carbon $when = null): WeeklyRecap
    {
        $now = $when ?? Carbon::today();
        $weekEnding = $now->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $weekStart = $weekEnding->copy()->subDays(6)->startOfDay();

        $thisWeek = $this->snapshotForWeek($user, $weekEnding);
        $lastWeek = $this->snapshotForWeek($user, $weekEnding->copy()->subWeek());

        $thisWeekKm = $thisWeek === null ? 0.0 : (float) ($thisWeek->distance_km ?? 0.0);
        $thisWeekRuns = $thisWeek === null ? 0 : (int) ($thisWeek->runs ?? 0);
        $lastWeekKm = $lastWeek === null ? 0.0 : (float) ($lastWeek->distance_km ?? 0.0);

        // Built once and reused for both the streak and the nearest goal so the
        // GamificationContext queries (including the week-streak scan) run a
        // single time per recap rather than once here and again inside GoalResolver.
        $ctx = GamificationContext::forUser($user);

        return new WeeklyRecap(
            weekStart: $weekStart->toDateString(),
            weekEnd: $weekEnding->toDateString(),
            thisWeekKm: $thisWeekKm,
            thisWeekRuns: $thisWeekRuns,
            lastWeekKm: $lastWeekKm,
            deltaPct: $this->deltaPct($thisWeekKm, $lastWeek, $lastWeekKm),
            streakWeeks: $ctx->streakWeeks,
            bestCard: $this->bestCardOfWeek($user, $weekStart, $weekEnding),
            nearestGoal: $this->nearestGoal($user, $ctx),
        );
    }

    private function snapshotForWeek(User $user, Carbon $weekEnding): ?WeeklySnapshot
    {
        return WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->whereDate('week_ending', $weekEnding->toDateString())
            ->first();
    }

    /**
     * Signed whole-percent km change vs last week. Null when there is no
     * comparable baseline: no prior snapshot, or last week's km was 0 (so the
     * percentage would divide by zero / be infinite).
     */
    private function deltaPct(float $thisWeekKm, ?WeeklySnapshot $lastWeek, float $lastWeekKm): ?int
    {
        if ($lastWeek === null || $lastWeekKm <= 0.0) {
            return null;
        }

        return (int) round((($thisWeekKm - $lastWeekKm) / $lastWeekKm) * 100);
    }

    /**
     * The highest-rarity card among this week's activities. Ties on rarity break
     * toward the most recent run. Null when no runs landed this week.
     *
     * @return array{id: int, rarity: string, special_move: string, mood: string|null, distance_km: float|null, polyline: string|null, date: string|null}|null
     */
    private function bestCardOfWeek(User $user, Carbon $weekStart, Carbon $weekEnding): ?array
    {
        $cards = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('activity.detail', fn ($q) => $q
                ->whereBetween('start_date_local', [
                    $weekStart->toDateString() . ' 00:00:00',
                    $weekEnding->toDateString() . ' 23:59:59',
                ]))
            ->with(['activity.detail:id,activity_id,distance,summary_polyline,start_date_local', 'activity.postRunStoryLine:id,activity_id,mood'])
            ->get();

        if ($cards->isEmpty()) {
            return null;
        }

        $best = $cards->sort(function (RunCard $a, RunCard $b): int {
            $rarityCompare = $b->rarity->rank() <=> $a->rarity->rank();
            if ($rarityCompare !== 0) {
                return $rarityCompare;
            }

            $aDate = $a->activity->detail?->start_date_local;
            $bDate = $b->activity->detail?->start_date_local;

            return ($bDate?->getTimestamp() ?? 0) <=> ($aDate?->getTimestamp() ?? 0);
        })->first();

        // The collection is non-empty (guarded above), so first() is set.
        \assert($best instanceof RunCard);

        $detail = $best->activity->detail;
        $mood = $best->activity->postRunStoryLine?->mood;

        return [
            'id' => $best->id,
            'rarity' => $best->rarity->value,
            'special_move' => $best->special_move,
            'mood' => $mood !== null ? (string) $mood : null,
            'distance_km' => $detail?->distance !== null ? round($detail->distance / 1000, 2) : null,
            'polyline' => $detail?->summary_polyline,
            'date' => $detail?->start_date_local?->toDateString(),
        ];
    }

    /**
     * The closest-to-completion incomplete goal, via GoalResolver. Returns its
     * label, progress ratio, and the "X lari/km lagi" remainder. Null when every
     * goal is already complete.
     *
     * @return array{id: string, title: string, current: int|float, target: int|float, unit: string, ratio: float, remainder_label: string}|null
     */
    private function nearestGoal(User $user, GamificationContext $ctx): ?array
    {
        $goals = $this->goalResolver->forUser($user, $ctx);
        $closest = $this->goalResolver->closestToCompletion($user, 1, $goals);
        $goal = $closest[0] ?? null;

        if ($goal === null || $goal['is_completed']) {
            return null;
        }

        $target = $goal['target'];
        $ratio = $target > 0 ? min($goal['current'] / $target, 1.0) : 0.0;
        $remaining = max($target - $goal['current'], 0);
        // Whole goals (lari/PR/kartu) read as integers; km goals keep one decimal.
        $remainder = fmod((float) $remaining, 1.0) !== 0.0 ? round($remaining, 1) : (int) $remaining;

        return [
            'id' => $goal['id'],
            'title' => $goal['title'],
            'current' => $goal['current'],
            'target' => $target,
            'unit' => $goal['unit'],
            'ratio' => $ratio,
            'remainder_label' => "{$remainder} {$goal['unit']} lagi",
        ];
    }
}
