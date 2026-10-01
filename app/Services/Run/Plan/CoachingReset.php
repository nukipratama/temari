<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Story\RecomputeCardClaimsAction;
use App\Enums\IntentVerdict;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\Season;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Trend\TrendSnapshotWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The one-time pre-launch reset (#1541): every derived record is rebuilt from
 * the stored runs under the current policy, history is re-graded, and the
 * athlete's narration is marked stale, never re-narrated. Raw runs and the
 * analytics cost history are not touched. A user already reset is skipped,
 * so a repeat apply changes nothing. Ordinary recalibration never comes here.
 */
final readonly class CoachingReset
{
    private const int LOCK_TTL_SECONDS = 3600;

    /** Subject types whose `subject_id` is the user id. */
    private const array USER_SUBJECT_TYPES = [
        AnalysisType::BRIEFING_SUBJECT_TYPE,
        AnalysisType::PROFILE_VOICE_SUBJECT_TYPE,
        AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        AnalysisType::TREND_READ_SUBJECT_TYPE,
        AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
    ];

    public function __construct(
        private PlanRecalibrationService $recalibration,
        private PersonalRecords $personalRecords,
        private WeeklyAggregator $weeklyAggregator,
        private TrendSnapshotWriter $trendSnapshots,
        private RecomputeCardClaimsAction $cardClaims,
        private SeasonService $seasons,
        private Periodizer $periodizer,
        private AnalysisService $analyses,
    ) {
    }

    /**
     * @return array{skipped: bool, before: array<string, int>, after: array<string, int>}
     */
    public function reset(User $user, bool $dryRun = false): array
    {
        if ($user->coaching_reset_at !== null) {
            return ['skipped' => true, 'before' => [], 'after' => []];
        }

        $result = null;
        $this->analyses->withoutDispatching(function () use ($user, $dryRun, &$result): void {
            $result = $this->recalibration->exclusively($user, $dryRun, self::LOCK_TTL_SECONDS, function (User $user): array {
                $today = Carbon::today();
                $before = self::counts($user, $today);

                $this->recalibration->recomputeSummaries($user);
                $this->personalRecords->rebuildForUser($user);
                $this->weeklyAggregator->rebuildFor($user);
                $this->trendSnapshots->writeRange($user, self::historyStart($user) ?? $today, $today);
                ($this->cardClaims)($user);
                $this->recalibration->rewriteHistory($user);
                $this->seasons->reanchorForReset($user, $today);
                $this->periodizer->regenerateWithinLock($user);
                self::ownedAnalyses($user)
                    ->where('status', AnalysisStatus::Done)
                    ->whereNull('stale_at')
                    ->update(['stale_at' => Carbon::now()]);
                $user->forceFill(['coaching_reset_at' => Carbon::now()])->saveQuietly();

                return ['skipped' => false, 'before' => $before, 'after' => self::counts($user, $today)];
            });
        });

        /** @var array{skipped: bool, before: array<string, int>, after: array<string, int>} $result */
        return $result;
    }

    /**
     * What the reset touches, counted the same way before and after it.
     *
     * @return array<string, int>
     */
    public static function counts(User $user, Carbon $today): array
    {
        $past = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '<', $today);
        $statuses = (clone $past)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();

        return [
            'runs summarised' => ActivityDetail::query()->whereIn('activity_id', Activity::query()->where('user_id', $user->id)->select('id'))->whereNotNull('stream_summary')->count(),
            'personal records' => PersonalRecord::query()->where('user_id', $user->id)->count(),
            'weekly snapshots' => WeeklySnapshot::query()->where('user_id', $user->id)->count(),
            'trend snapshots' => TrendDailySnapshot::query()->where('user_id', $user->id)->count(),
            'cards with a PR' => RunCard::query()->whereIn('activity_id', Activity::query()->where('user_id', $user->id)->select('id'))->where('pr_set', true)->count(),
            'past days done' => (int) ($statuses['done'] ?? 0),
            'past days partial' => (int) ($statuses['partial'] ?? 0),
            'past days overreached' => (int) ($statuses['overreached'] ?? 0),
            'past days missed' => (int) ($statuses['missed'] ?? 0),
            'past days skipped' => (int) ($statuses['skip'] ?? 0),
            'past days ungraded' => (int) ($statuses['planned'] ?? 0),
            'past days unknown effort' => (clone $past)->where('intent_verdict', IntentVerdict::Unknown)->count(),
            'days ahead' => PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '>=', $today)->count(),
            'seasons' => Season::query()->where('user_id', $user->id)->count(),
            'stale narrations' => self::ownedAnalyses($user)->whereNotNull('stale_at')->count(),
        ];
    }

    private static function historyStart(User $user): ?Carbon
    {
        $first = ActivityDetail::query()
            ->whereIn('activity_id', Activity::query()->where('user_id', $user->id)->select('id'))
            ->min('start_date_local');

        return $first === null ? null : Carbon::parse($first)->startOfDay();
    }

    /** @return Builder<Analysis> */
    private static function ownedAnalyses(User $user): Builder
    {
        $activities = Activity::query()->where('user_id', $user->id)->select('id');

        return Analysis::query()->where(function (Builder $query) use ($user, $activities): void {
            $query->where(fn (Builder $q) => $q->whereIn('subject_type', self::USER_SUBJECT_TYPES)->where('subject_id', $user->id))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', Activity::class)->whereIn('subject_id', $activities))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', RunCard::class)->whereIn('subject_id', RunCard::query()->whereIn('activity_id', $activities)->select('id')))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', WeeklySnapshot::class)->whereIn('subject_id', WeeklySnapshot::query()->where('user_id', $user->id)->select('id')))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', Season::class)->whereIn('subject_id', Season::query()->where('user_id', $user->id)->select('id')));
        });
    }
}
