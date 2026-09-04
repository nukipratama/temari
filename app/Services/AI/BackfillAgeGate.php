<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\ActivityDetail;
use App\Models\RunCard;
use Illuminate\Support\Carbon;

/**
 * Whether a subject's material is older than `ai.backfill_max_age_days`, in which
 * case it is narrated by {@see \App\Services\AI\RuleBased\RuleBasedNarrationFiller}
 * instead of the LLM. See docs/decisions/twelve-week-narration-cutoff.md.
 */
class BackfillAgeGate
{
    /** The oldest instant still narratable by the LLM. */
    public function cutoff(): Carbon
    {
        return Carbon::now()->subDays((int) config('ai.backfill_max_age_days'));
    }

    /** The cutoff as the `Y-m-d` key a date-keyed subject compares against. */
    public function cutoffDate(): string
    {
        return $this->cutoff()->toDateString();
    }

    /** The cutoff as the `Y-m` key a month-keyed subject compares against. */
    public function cutoffMonth(): string
    {
        return $this->cutoff()->format('Y-m');
    }

    public function isTooOld(?Carbon $startedAt): bool
    {
        if ($startedAt === null) {
            return false;
        }

        return Carbon::now()->diffInDays($startedAt, absolute: true) >= (int) config('ai.backfill_max_age_days');
    }

    /**
     * Whether a manual "Reread" on this subject must be served rule-based.
     *
     * Exhaustive on purpose (no `default`): a new AnalysisType must state
     * whether its manual trigger can reach material older than the cutoff.
     */
    public function blocksManualTrigger(AnalysisType $type, int $subjectId, ?string $discriminator): bool
    {
        return match ($type) {
            AnalysisType::CardFlavor => $this->isTooOld($this->runDateForCard($subjectId)),
            AnalysisType::BriefingMascotVoice => $this->isTooOld($this->parseDay($discriminator)),
            // Chained: ChainResolver::isHeadRegenerate() admits only the true
            // chain head, so an old link resumes the chain forward instead of
            // narrating itself.
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsight,
            AnalysisType::WeeklyRecap,
            AnalysisType::MonthlyRecap => false,
            // Narrate material that is current whatever its date: the profile
            // voice reads a rolling window as of now and ignores its week key.
            // TrendRead is the same shape — its discriminator names a range
            // (30d/90d/12mo), not a date, and it is always read as of now
            // regardless of how old the user's history is.
            AnalysisType::ProfileVoice,
            AnalysisType::TrendRead,
            // Same shape: plan narration is always about the current week or
            // season as of now, never a fixed past date to age out.
            AnalysisType::PlanDayVoice,
            AnalysisType::PlanWeekVoice,
            AnalysisType::PlanSeasonVoice => false,
        };
    }

    private function runDateForCard(int $cardId): ?Carbon
    {
        $activityId = RunCard::query()->whereKey($cardId)->value('activity_id');

        return $activityId === null ? null : $this->runDate((int) $activityId);
    }

    private function runDate(int $activityId): ?Carbon
    {
        return ActivityDetail::query()
            ->where('activity_id', $activityId)
            ->first(['start_date_local'])
            ?->start_date_local;
    }

    private function parseDay(?string $day): ?Carbon
    {
        return $day === null ? null : Carbon::parse($day);
    }
}
