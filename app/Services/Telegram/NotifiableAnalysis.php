<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;
use Illuminate\Support\Carbon;

/**
 * Registry of the analysis types that fan out a Telegram notification when they
 * complete, and how each one resolves its user, preference flag, and message.
 * Adding a third notifiable event is a single entry here. See the AI-pipeline
 * note for the markDone hook that consults this.
 */
class NotifiableAnalysis
{
    /**
     * Map of notifiable type to the NotificationPreference boolean column that
     * gates it (channel-neutral: the same opt-in governs Telegram + web push), the
     * emoji prefixed to the narration content (a glanceable label without a
     * redundant text header), and the tap-through CTA appended before the link.
     *
     * @var array<string, array{pref: string, emoji: string, title: string, cta: string}>
     */
    private const array TYPES = [
        AnalysisType::PostRunSpeech->value => ['pref' => 'post_run', 'emoji' => '🏃', 'title' => 'Cerita lari', 'cta' => 'Lihat detail lari'],
        AnalysisType::WeeklyRecap->value => ['pref' => 'weekly_recap', 'emoji' => '📊', 'title' => 'Rekap mingguan', 'cta' => 'Lihat riwayat'],
        AnalysisType::MonthlyRecap->value => ['pref' => 'monthly_recap', 'emoji' => '🗓️', 'title' => 'Rekap bulanan', 'cta' => 'Lihat kalender'],
    ];

    /**
     * Per-instance memo of activity_id => ActivityDetail, so a single send
     * (recency gate + metrics line both look up the same row) hits the DB once.
     *
     * @var array<int, ActivityDetail|null>
     */
    private array $detailCache = [];

    public function isNotifiable(Analysis $analysis): bool
    {
        return array_key_exists($analysis->analysis_type->value, self::TYPES);
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

    /**
     * Whether the user has opted in to notifications for this analysis type. The
     * opt-in is channel-neutral; a missing preference row means all-on (default).
     */
    public function isOptedIn(Analysis $analysis, User $user): bool
    {
        $column = self::TYPES[$analysis->analysis_type->value]['pref'] ?? null;
        if ($column === null) {
            return false;
        }

        $preference = $user->notificationPreference;

        return $preference === null || (bool) $preference->{$column};
    }

    /** The user this analysis belongs to, or null when it can't be resolved. */
    public function resolveUser(Analysis $analysis): ?User
    {
        $userId = $analysis->ownerId();

        return $userId !== null ? User::query()->find($userId) : null;
    }

    /**
     * The Telegram message body: a short header, the narration content, and a
     * tap-through link to the relevant page (Telegram auto-links the bare URL).
     */
    public function format(Analysis $analysis): string
    {
        $meta = self::TYPES[$analysis->analysis_type->value] ?? null;

        $message = trim(($meta['emoji'] ?? '') . ' ' . (string) $analysis->content);

        $metrics = $this->metricsLine($analysis);
        if ($metrics !== null) {
            $message .= "\n\n" . $metrics;
        }

        $url = $this->url($analysis);
        if ($meta !== null && $url !== null) {
            $message .= "\n\n" . $meta['cta'] . ': ' . $url;
        }

        return $message;
    }

    /**
     * A one-line "5.20 km · 34:14 · 6:35/km · 159 bpm" metrics summary for a
     * post-run notification, or null for other types / when the activity has no
     * detail. Each metric is dropped individually when its column is null (e.g.
     * HR on a strap-less run).
     */
    private function metricsLine(Analysis $analysis): ?string
    {
        if ($analysis->analysis_type !== AnalysisType::PostRunSpeech) {
            return null;
        }

        $detail = $this->activityDetail($analysis->subject_id);
        if ($detail === null) {
            return null;
        }

        $parts = [];
        if ($detail->distance !== null) {
            $parts[] = number_format($detail->distance / 1000, 2) . ' km';
        }
        if ($detail->moving_time !== null) {
            $parts[] = $this->formatDuration($detail->moving_time);
        }
        $pace = PaceCalculator::secPerKm($detail->distance, $detail->moving_time);
        if ($pace !== null) {
            $parts[] = PaceFormatter::format($pace) . '/km';
        }
        if ($detail->average_heartrate !== null) {
            $parts[] = (int) round($detail->average_heartrate) . ' bpm';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function activityDetail(int $activityId): ?ActivityDetail
    {
        if (! array_key_exists($activityId, $this->detailCache)) {
            $this->detailCache[$activityId] = ActivityDetail::query()->where('activity_id', $activityId)->first();
        }

        return $this->detailCache[$activityId];
    }

    /** Seconds to mm:ss, or h:mm:ss past an hour (no backend duration formatter exists). */
    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }

    /** Absolute app URL the notification links to, or null when not resolvable. */
    public function url(Analysis $analysis): ?string
    {
        return match ($analysis->analysis_type) {
            AnalysisType::PostRunSpeech => route('aktivitas.show', $analysis->subject_id),
            AnalysisType::WeeklyRecap => route('aktivitas.index'),
            AnalysisType::MonthlyRecap => route('kalender', ['month' => $analysis->discriminator]),
            default => null,
        };
    }

    /** The web-push notification title: emoji + a short type label. */
    public function pushTitle(Analysis $analysis): string
    {
        $meta = self::TYPES[$analysis->analysis_type->value] ?? null;

        return $meta === null ? 'Temari' : trim($meta['emoji'] . ' ' . $meta['title']);
    }
}
