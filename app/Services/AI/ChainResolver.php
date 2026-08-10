<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ChainResolver
{
    /**
     * Whether the clicked row is a legitimate head regenerate: a Done row that
     * is the latest narrated link of the user's chain. Only the head may
     * regenerate; re-narrating a mid-history link would desync later blocks.
     * Returns false for non-Done rows and for unknown chained types (which fall
     * through to the resume path).
     *
     * Keying differs per kind: WeeklyRecap is keyed by the WeeklySnapshot
     * subject id; MonthlyRecap is keyed by the discriminator month (Y-m) under a
     * single user subject, so its head is matched on the discriminator.
     */
    public function isHeadRegenerate(User $user, AnalysisType $type, int $subjectId, ?string $discriminator, ?Analysis $existing): bool
    {
        if ($existing?->status !== AnalysisStatus::Done) {
            return false;
        }

        return match ($type) {
            AnalysisType::WeeklyRecap => $subjectId === $this->weeklyChainHeadId($user),
            AnalysisType::MonthlyRecap => $discriminator !== null && $discriminator === $this->monthlyChainHeadMonth($user),
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsight => $subjectId === Activity::latestIdForUser($user->id),
            default => false,
        };
    }

    /**
     * The earliest unfilled link of the user's chain for a chained type, or null
     * when there is nothing earlier to resume (the clicked row is then used
     * as-is). "Unfilled" = no Done recap; walking from oldest forward fills the
     * chronological gap so each successor still reads a Done predecessor.
     * Returns null for an unknown chained type so the caller keeps the clicked
     * row's identity.
     */
    public function earliestUnfilledLink(User $user, AnalysisType $type): ?ChainLink
    {
        return match ($type) {
            AnalysisType::WeeklyRecap => $this->earliestUnfilledWeeklyLink($user),
            AnalysisType::MonthlyRecap => $this->earliestUnfilledMonthlyLink($user),
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsight => $this->earliestUnfilledActivityLink($user, $type),
            default => null,
        };
    }

    /**
     * Weekly chains: the earliest stalled WeeklyRecap per user (runs > 0) among
     * the fully-closed weeks. "Stalled" = Pending or Failed under the retry
     * budget ({@see Analysis::scopeStalled}), so this recovers a link a transient
     * failure or cost-ceiling pause left behind without re-billing a block that
     * has burned its budget. Capped at the latest closed week so the sweep never
     * narrates the still-running current week on incomplete data (the weekly
     * kickoff owns first-narration). Demo is excluded so it never auto-bills.
     *
     * The returned link's `discriminator` carries `week_ending` (unused
     * otherwise for weekly links) so a caller can age-check against the
     * backfill depth cap without a second query.
     *
     * @return Collection<int, ChainLink>
     */
    public function stalledWeeklyLinkPerUser(): Collection
    {
        return Analysis::query()
            ->stalled()
            ->where('ai_analyses.subject_type', WeeklySnapshot::class)
            ->where('ai_analyses.analysis_type', AnalysisType::WeeklyRecap)
            ->join('weekly_snapshots', 'weekly_snapshots.id', '=', 'ai_analyses.subject_id')
            ->where('weekly_snapshots.runs', '>', 0)
            ->where('weekly_snapshots.week_ending', '<=', RecapPeriod::lastClosedWeekEnding())
            ->whereIn('weekly_snapshots.user_id', User::query()->notDemo()->select('id'))
            ->orderBy('weekly_snapshots.week_ending')
            ->get(['ai_analyses.subject_id', 'weekly_snapshots.user_id', 'weekly_snapshots.week_ending'])
            ->unique('user_id')
            ->map(fn (Analysis $row): ChainLink => new ChainLink((int) $row->subject_id, (string) $row->getAttribute('week_ending')))
            ->values();
    }

    /**
     * Monthly chains: the earliest stalled MonthlyRecap month per user among the
     * fully-closed months. "Stalled" = Pending or Failed under the retry budget.
     * Capped at the latest closed month so the sweep never narrates the
     * still-running current month (the monthly kickoff owns first-narration).
     * Demo never stages a monthly row, so it is naturally absent here.
     *
     * @return Collection<int, ChainLink>
     */
    public function stalledMonthlyLinkPerUser(): Collection
    {
        return Analysis::query()
            ->stalled()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('discriminator', '<=', RecapPeriod::lastClosedMonth())
            ->orderBy('discriminator')
            ->get(['subject_id', 'discriminator'])
            ->unique('subject_id')
            ->map(fn (Analysis $row): ChainLink => new ChainLink((int) $row->subject_id, $row->discriminator))
            ->values();
    }

    private function earliestUnfilledWeeklyLink(User $user): ?ChainLink
    {
        $earliest = $this->narratableWeeks($user)
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done))
            ->orderBy('week_ending')
            ->first();

        return $earliest === null ? null : new ChainLink((int) $earliest->id);
    }

    /**
     * The monthly chain's earliest unfilled (not Done) month for the user. The
     * chain links are the pre-staged Analysis rows themselves (keyed by the Y-m
     * discriminator under the user subject), so this walks those rows rather than
     * a per-month subject table. The still-running current month is excluded (its
     * row is staged Pending but inert until the month closes), so resuming never
     * narrates an incomplete month.
     */
    private function earliestUnfilledMonthlyLink(User $user): ?ChainLink
    {
        $earliest = Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('status', '!=', AnalysisStatus::Done)
            ->where('discriminator', '<=', RecapPeriod::lastClosedMonth())
            ->orderBy('discriminator')
            ->first();

        return $earliest === null ? null : new ChainLink($user->id, $earliest->discriminator);
    }

    /**
     * The per-activity chain's earliest unfilled (not Done) link for the clicked
     * type. The chain is keyed by the Activity id (discriminator null) and
     * ordered by start_date_local, so this walks the user's activities oldest
     * first for the first one whose clicked-type row is not Done, resuming the
     * group from there. Returns null when every activity's row is already Done
     * (the clicked row is then used as-is, which the head-regenerate path
     * handles).
     */
    private function earliestUnfilledActivityLink(User $user, AnalysisType $type): ?ChainLink
    {
        $earliest = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', $type)
                ->where('status', AnalysisStatus::Done))
            ->orderBy('activity_details.start_date_local')
            ->select('activities.id')
            ->first();

        return $earliest === null ? null : new ChainLink((int) $earliest->id);
    }

    /** The WeeklySnapshot id of the user's latest completed running week, or null. */
    private function weeklyChainHeadId(User $user): ?int
    {
        $headId = $this->narratableWeeks($user)
            ->orderByDesc('week_ending')
            ->value('id');

        return $headId === null ? null : (int) $headId;
    }

    /**
     * The latest closed month (Y-m) the user has a MonthlyRecap row for, or null.
     * Capped at the last fully-closed month so the still-running current month's
     * inert Pending row is never treated as the regenerable chain head.
     */
    private function monthlyChainHeadMonth(User $user): ?string
    {
        return Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('discriminator', '<=', RecapPeriod::lastClosedMonth())
            ->orderByDesc('discriminator')
            ->value('discriminator');
    }

    /** @return Builder<WeeklySnapshot> */
    private function narratableWeeks(User $user): Builder
    {
        return WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', RecapPeriod::lastClosedWeekEnding())
            ->where('runs', '>', 0);
    }
}
