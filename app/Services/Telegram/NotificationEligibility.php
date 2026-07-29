<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

/**
 * Whether a completed analysis is notifiable at all, whether an automatic push
 * for it is still fresh enough to send, who it belongs to, and whether that
 * user has opted in. See {@see AnalysisMessagePresenter} for the message text
 * built from an already-eligible analysis.
 */
class NotificationEligibility
{
    /**
     * Per-instance memo of activity_id => ActivityDetail, so a repeated
     * recency-gate check for the same activity hits the DB once.
     *
     * @var array<int, ActivityDetail|null>
     */
    private array $detailCache = [];

    public function isNotifiable(Analysis $analysis): bool
    {
        return array_key_exists($analysis->analysis_type->value, NotifiableAnalysisTypes::TYPES);
    }

    /**
     * Whether an automatic push is still relevant to send. A big Strava backfill
     * stages hundreds of old per-run and historical recap narrations that
     * eventually complete via the deferred chain (see
     * DispatchPostRunAnalysis::isBackfill); without this, each one would still
     * push to Telegram once done. Gates by the age of the type's reference date
     * (the run's start, the week's ending, the recap month's end) against
     * `notify_max_age_days`, so only the freshest period pings and history stays
     * quiet. Types with no reference date, or a missing one, are never gated.
     * Only the automatic path — the manual "Kirim ke Telegram" push (force)
     * bypasses it on purpose.
     */
    public function isRecentEnoughToAutoNotify(Analysis $analysis): bool
    {
        $reference = $this->autoNotifyReferenceDate($analysis);
        if ($reference === null) {
            return true;
        }

        $maxDays = (int) config('services.telegram.notify_max_age_days');

        return $reference->diffInDays(Carbon::now(), absolute: true) <= $maxDays;
    }

    /**
     * The date an automatic push for this type is measured against, or null when
     * its reference can't be resolved (missing activity/snapshot, blank
     * discriminator).
     */
    private function autoNotifyReferenceDate(Analysis $analysis): ?Carbon
    {
        return match ($analysis->analysis_type) {
            AnalysisType::PostRunSpeech => $this->carbonOrNull($this->activityDetail($analysis->subject_id)?->start_date_local),
            AnalysisType::WeeklyRecap => $this->carbonOrNull(WeeklySnapshot::query()->find($analysis->subject_id)?->week_ending),
            AnalysisType::MonthlyRecap => $this->carbonOrNull($analysis->discriminator)?->endOfMonth(),
            default => null,
        };
    }

    private function carbonOrNull(mixed $date): ?Carbon
    {
        return $date === null ? null : Carbon::parse($date);
    }

    private function activityDetail(int $activityId): ?ActivityDetail
    {
        if (! array_key_exists($activityId, $this->detailCache)) {
            $this->detailCache[$activityId] = ActivityDetail::query()->where('activity_id', $activityId)->first();
        }

        return $this->detailCache[$activityId];
    }

    /**
     * Whether the user has opted in to notifications for this analysis type. One
     * master switch covers every notifiable type; it is channel-neutral, and a
     * missing preference row means all-on (default).
     */
    public function isOptedIn(Analysis $analysis, User $user): bool
    {
        if (! $this->isNotifiable($analysis)) {
            return false;
        }

        $preference = $user->notificationPreference;

        return $preference === null || $preference->notifications_enabled;
    }

    /** The user this analysis belongs to, or null when it can't be resolved. */
    public function resolveUser(Analysis $analysis): ?User
    {
        $userId = AnalysisSubjectMap::ownerId($analysis->subject_type, $analysis->subject_id);

        return $userId !== null ? User::query()->find($userId) : null;
    }
}
