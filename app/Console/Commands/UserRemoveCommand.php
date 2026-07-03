<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('user:remove {id : The user id to permanently remove} {--force : Skip the confirmation prompt}')]
#[Description('Permanently remove a user and all owned data (runs, cards, narration). Keeps ai_token_usages for cost history.')]
class UserRemoveCommand extends Command
{
    /**
     * ai_analyses subject_type strings keyed directly by user id (the per-user /
     * per-day / per-month narration subjects). Activity / RunCard / WeeklySnapshot
     * / PersonalRecord subjects are matched by their own ids instead.
     */
    private const array USER_SUBJECT_TYPES = [
        AnalysisType::BRIEFING_SUBJECT_TYPE,
        AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
        AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
        AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE,
        AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE,
        AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
    ];

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $user = User::query()->find($id);

        if ($user === null) {
            $this->error("User {$id} not found.");

            return self::FAILURE;
        }

        if ($user->is_demo) {
            $this->error("Refusing to remove the demo user (id {$id}). Reset it with `demo:seed` instead.");

            return self::FAILURE;
        }

        $activityIds = Activity::query()->where('user_id', $id)->pluck('id');
        $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id');
        $snapshotIds = WeeklySnapshot::query()->where('user_id', $id)->pluck('id');
        $personalRecordIds = PersonalRecord::query()->where('user_id', $id)->pluck('id');

        $analysisCount = $this->analysisQuery($id, $activityIds, $cardIds, $snapshotIds, $personalRecordIds)->count();
        $tokenUsageCount = TokenUsage::query()->where('user_id', $id)->count();

        $this->table(['What', 'Count'], [
            ['User', "{$user->name} <{$user->email}> (id {$id})"],
            ['Activities (+ details, streams, cards, PRs, story lines)', (string) $activityIds->count()],
            ['Run cards', (string) $cardIds->count()],
            ['Weekly snapshots', (string) $snapshotIds->count()],
            ['Personal records', (string) $personalRecordIds->count()],
            ['AI analyses (deleted)', (string) $analysisCount],
            ['AI token-usage rows (KEPT, will orphan)', (string) $tokenUsageCount],
        ]);

        if (! $this->option('force')
            && ! $this->confirm("Permanently remove user {$id} and all owned data? This cannot be undone.")) {
            $this->info('Aborted, nothing removed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($id, $user): void {
            // Re-resolve the owned ids inside the transaction rather than reusing
            // the ones fetched for the preview table: activity/card/etc rows can
            // be created for this user (e.g. a Strava webhook) in the window
            // between the preview and an interactive confirmation.
            $activityIds = Activity::query()->where('user_id', $id)->pluck('id');
            $cardIds = RunCard::query()->whereIn('activity_id', $activityIds)->pluck('id');
            $snapshotIds = WeeklySnapshot::query()->where('user_id', $id)->pluck('id');
            $personalRecordIds = PersonalRecord::query()->where('user_id', $id)->pluck('id');

            // ai_analyses is polymorphic with no user FK, so it never cascades:
            // delete it explicitly before the user row.
            $this->analysisQuery($id, $activityIds, $cardIds, $snapshotIds, $personalRecordIds)->delete();

            // Everything else (activities -> details/streams/cards/PRs, story
            // lines, snapshots, unlocks, profiles, connections) cascades off the
            // user row's foreign keys.
            $user->delete();
        });

        $this->info("Removed user {$id}. Kept {$tokenUsageCount} ai_token_usages row(s) for cost history (now orphaned under the old id).");

        return self::SUCCESS;
    }

    /**
     * All ai_analyses rows owned by the user: their activity / card / snapshot
     * / personal-record subjects, plus the user-keyed daily/monthly/persona
     * subjects.
     *
     * @param  Collection<int, int>  $activityIds
     * @param  Collection<int, int>  $cardIds
     * @param  Collection<int, int>  $snapshotIds
     * @param  Collection<int, int>  $personalRecordIds
     * @return Builder<Analysis>
     */
    private function analysisQuery(int $userId, Collection $activityIds, Collection $cardIds, Collection $snapshotIds, Collection $personalRecordIds): Builder
    {
        return Analysis::query()->where(function (Builder $query) use ($userId, $activityIds, $cardIds, $snapshotIds, $personalRecordIds): void {
            $query
                ->where(fn (Builder $q) => $q->where('subject_type', Activity::class)->whereIn('subject_id', $activityIds))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', RunCard::class)->whereIn('subject_id', $cardIds))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', WeeklySnapshot::class)->whereIn('subject_id', $snapshotIds))
                ->orWhere(fn (Builder $q) => $q->where('subject_type', PersonalRecord::class)->whereIn('subject_id', $personalRecordIds))
                ->orWhere(fn (Builder $q) => $q->whereIn('subject_type', self::USER_SUBJECT_TYPES)->where('subject_id', $userId));
        });
    }
}
