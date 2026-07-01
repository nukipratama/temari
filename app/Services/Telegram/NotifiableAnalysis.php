<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\TelegramConnection;
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
     * Map of notifiable type to the TelegramConnection boolean column that gates
     * it, the emoji prefixed to the narration content (a glanceable label without
     * a redundant text header), and the tap-through CTA appended before the link.
     *
     * @var array<string, array{pref: string, emoji: string, cta: string}>
     */
    private const array TYPES = [
        AnalysisType::PostRunSpeech->value => ['pref' => 'notify_post_run', 'emoji' => '🏃', 'cta' => 'Lihat detail lari'],
        AnalysisType::WeeklyRecap->value => ['pref' => 'notify_weekly_recap', 'emoji' => '📊', 'cta' => 'Lihat riwayat'],
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
     * Whether an automatic post-run push is still relevant to send. A big Strava
     * backfill stages hundreds of old PostRunSpeech narrations that eventually
     * complete via the deferred chain (see DispatchPostRunAnalysis::isBackfill);
     * without this, each one would still push to Telegram once done. Only gates
     * PostRunSpeech, and only the automatic path — the manual "Kirim ke Telegram"
     * push (force) bypasses it on purpose.
     */
    public function isRecentEnoughToAutoNotify(Analysis $analysis): bool
    {
        if ($analysis->analysis_type !== AnalysisType::PostRunSpeech) {
            return true;
        }

        $startedAt = $this->activityDetail($analysis->subject_id)?->start_date_local;
        if ($startedAt === null) {
            return true;
        }

        $maxDays = (int) config('services.telegram.notify_max_age_days');

        return Carbon::parse($startedAt)->diffInDays(Carbon::now(), absolute: true) <= $maxDays;
    }

    /** Whether the connection has opted in to notifications for this analysis type. */
    public function isOptedIn(Analysis $analysis, TelegramConnection $connection): bool
    {
        $column = self::TYPES[$analysis->analysis_type->value]['pref'] ?? null;

        return $column !== null && (bool) $connection->{$column};
    }

    /** The user this analysis belongs to, or null when it can't be resolved. */
    public function resolveUser(Analysis $analysis): ?User
    {
        return match ($analysis->analysis_type) {
            AnalysisType::PostRunSpeech => Activity::query()->find($analysis->subject_id)?->user,
            AnalysisType::WeeklyRecap => WeeklySnapshot::query()->find($analysis->subject_id)?->user,
            default => null,
        };
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
            default => null,
        };
    }
}
