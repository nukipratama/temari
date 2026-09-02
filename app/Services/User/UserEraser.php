<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\Scopes\KnownAnalysisTypeScope;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a user and everything they own.
 *
 * Most owned tables cascade off the `users` row's foreign keys. Two do not, and
 * both are polymorphic with no user column to constrain: `ai_analyses` (its
 * subject is an activity, card, snapshot, record, or a synthetic per-user
 * string) and `push_subscriptions` (a `subscribable` morph). They have to be
 * deleted by hand, which is why this lives in one place rather than in each
 * caller — the in-app delete button did not, and leaked both.
 *
 * `notification_deliveries` needs no handling here: it cascades off
 * `ai_analyses`, so removing those takes it with them.
 *
 * `ai_token_usages` is deliberately kept. It is cost history, not user data,
 * and lives on its own connection so it survives the account entirely. Before
 * the user goes, their name and Strava athlete id are stamped onto those rows,
 * so /ai-usage can still say whose spend it was instead of showing a bare id
 * pointing at nobody.
 */
final readonly class UserEraser
{
    /**
     * `ai_analyses.subject_type` strings keyed directly by user id (the
     * per-user / per-day / per-month narration subjects). Activity, RunCard,
     * WeeklySnapshot and PersonalRecord subjects are matched by their own ids.
     */
    private const array USER_SUBJECT_TYPES = [
        AnalysisType::BRIEFING_SUBJECT_TYPE,
        AnalysisType::PROFILE_VOICE_SUBJECT_TYPE,
        AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        AnalysisType::TREND_READ_SUBJECT_TYPE,
        // Retired narration types. Their AnalysisType cases are gone but the
        // historical rows are kept, and erasure must still reach them.
        'daily_greeting_user_day',
        'trend_caption_user_day',
        'persona_summary_user',
    ];

    public function erase(User $user): void
    {
        $id = $user->id;

        // Ahead of the transaction on purpose: ai_token_usages is on another
        // connection, so it would not roll back with the rest. Stamping first
        // means a failed delete leaves a snapshot on a user who still exists,
        // which is invisible — the report prefers live identity over it.
        $this->snapshotIdentityOntoUsage($user);

        DB::transaction(function () use ($id, $user): void {
            // Resolved inside the transaction rather than reused from any
            // caller's preview: rows can be created for this user (a Strava
            // webhook, say) between a confirmation prompt and this point.
            $activityIds = Activity::query()->where('user_id', $id)->pluck('id');
            $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id');
            $snapshotIds = WeeklySnapshot::query()->where('user_id', $id)->pluck('id');
            $personalRecordIds = PersonalRecord::query()->where('user_id', $id)->pluck('id');

            self::analysisQuery($id, $activityIds, $cardIds, $snapshotIds, $personalRecordIds)->delete();
            self::pushSubscriptionQuery($user)->delete();

            // Everything else (activities -> details/streams/cards/PRs, story
            // lines, snapshots, unlocks, profiles, connections) cascades.
            $user->delete();
        });
    }

    /**
     * Record who a usage row belonged to, so the spend stays attributable after
     * the user and their Strava connection are gone. Live users are left null
     * and resolved from the source tables, so a rename never goes stale here.
     */
    private function snapshotIdentityOntoUsage(User $user): void
    {
        TokenUsage::query()
            ->where('user_id', $user->id)
            ->update([
                'user_name' => $user->name,
                'strava_athlete_id' => $user->stravaConnection?->strava_athlete_id,
            ]);
    }

    /**
     * How many rows {@see self::erase()} would remove by hand, for a caller
     * that wants to show the damage before doing it.
     *
     * @return array{ai_analyses: int, push_subscriptions: int}
     */
    public function orphanCounts(User $user): array
    {
        $id = $user->id;
        $activityIds = Activity::query()->where('user_id', $id)->pluck('id');

        return [
            'ai_analyses' => self::analysisQuery(
                $id,
                $activityIds,
                RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id'),
                WeeklySnapshot::query()->where('user_id', $id)->pluck('id'),
                PersonalRecord::query()->where('user_id', $id)->pluck('id'),
            )->count(),
            'push_subscriptions' => self::pushSubscriptionQuery($user)->count(),
        ];
    }

    /**
     * All `ai_analyses` rows owned by the user: their activity / card /
     * snapshot / personal-record subjects, plus the user-keyed ones. Opts out
     * of {@see KnownAnalysisTypeScope} so a retired-type row (e.g. one of the
     * old per-lens RunInsight types) is still reached and removed rather than
     * silently surviving the user's own erasure.
     *
     * @param  Collection<int, int>  $activityIds
     * @param  Collection<int, int>  $cardIds
     * @param  Collection<int, int>  $snapshotIds
     * @param  Collection<int, int>  $personalRecordIds
     * @return Builder<Analysis>
     */
    private static function analysisQuery(
        int $userId,
        Collection $activityIds,
        Collection $cardIds,
        Collection $snapshotIds,
        Collection $personalRecordIds,
    ): Builder {
        return Analysis::query()->withoutGlobalScope(KnownAnalysisTypeScope::class)->where(function (Builder $query) use ($userId, $activityIds, $cardIds, $snapshotIds, $personalRecordIds): void {
            $query
                ->where(fn (Builder $q) => $q->where('subject_type', Activity::class)->whereIn('subject_id', $activityIds))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', RunCard::class)->whereIn('subject_id', $cardIds))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', WeeklySnapshot::class)->whereIn('subject_id', $snapshotIds))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', PersonalRecord::class)->whereIn('subject_id', $personalRecordIds))
                ->orWhere(fn (Builder $q) => $q->whereIn('subject_type', self::USER_SUBJECT_TYPES)->where('subject_id', $userId));
        });
    }

    /**
     * The user's web-push endpoints. The table is a `subscribable` morph with
     * no foreign key, so nothing removes these on its own.
     */
    private static function pushSubscriptionQuery(User $user): \Illuminate\Database\Query\Builder
    {
        return DB::connection(config('webpush.database_connection'))
            ->table((string) config('webpush.table_name'))
            ->where('subscribable_type', $user->getMorphClass())
            ->where('subscribable_id', $user->id);
    }
}
