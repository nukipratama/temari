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
     * emoji leading the title line, a data-less fallback `title` used when the
     * type's dynamic data can't be resolved, and the tap-through CTA appended
     * before the link.
     *
     * @var array<string, array{pref: string, emoji: string, title: string, cta: string}>
     */
    private const array TYPES = [
        AnalysisType::PostRunSpeech->value => ['pref' => 'post_run', 'emoji' => '🏃', 'title' => 'Lari kamu udah masuk! 🏁', 'cta' => 'Lihat detail lari'],
        AnalysisType::WeeklyRecap->value => ['pref' => 'weekly_recap', 'emoji' => '📊', 'title' => 'Rekap minggu lalu udah siap', 'cta' => 'Lihat riwayat'],
        AnalysisType::MonthlyRecap->value => ['pref' => 'monthly_recap', 'emoji' => '🗓️', 'title' => 'Rekap bulanan udah siap', 'cta' => 'Lihat kalender'],
    ];

    /**
     * Indonesian month names by month number, for the monthly-recap title.
     * Hardcoded rather than leaning on Carbon's `id` locale data, which isn't
     * guaranteed loaded in every runtime (it would silently fall back to English).
     *
     * @var array<int, string>
     */
    private const array MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
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
     * The Telegram message body, mirroring the web-push title→body hierarchy: the
     * same dynamic title line, a blank line, the narration content, then (post-run)
     * a metrics line and the tap-through link (Telegram auto-links the bare URL).
     */
    public function format(Analysis $analysis): string
    {
        $message = $this->title($analysis);

        $content = trim((string) $analysis->content);
        if ($content !== '') {
            $message .= "\n\n" . $content;
        }

        $metrics = $this->metricsLine($analysis);
        if ($metrics !== null) {
            $message .= "\n\n" . $metrics;
        }

        $meta = self::TYPES[$analysis->analysis_type->value] ?? null;
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
            AnalysisType::WeeklyRecap => $this->weeklyRecapUrl($analysis),
            AnalysisType::MonthlyRecap => route('kalender', ['month' => $analysis->discriminator]),
            default => null,
        };
    }

    /**
     * Deep link straight to the week the recap is about, rather than the bare
     * run history: tapping "your weekly recap is ready" should land on *that*
     * week, the way the monthly recap already lands on its month. Falls back to
     * the unfiltered list when the snapshot has gone (a deleted week shouldn't
     * make the notification a dead end).
     */
    private function weeklyRecapUrl(Analysis $analysis): string
    {
        $weekEnding = WeeklySnapshot::query()
            ->whereKey($analysis->subject_id)
            ->value('week_ending');

        return $weekEnding === null
            ? route('aktivitas.index')
            : route('aktivitas.index', ['week' => Carbon::parse($weekEnding)->toDateString()]);
    }

    /**
     * The notification title shared by web push and the Telegram body's first
     * line: an emoji plus a short, data-aware phrase (run distance, recap month).
     * Falls back to the type's data-less label when that data can't be resolved,
     * and to the app name for an unregistered type.
     */
    public function title(Analysis $analysis): string
    {
        $meta = self::TYPES[$analysis->analysis_type->value] ?? null;
        if ($meta === null) {
            return 'Temari';
        }

        $phrase = match ($analysis->analysis_type) {
            AnalysisType::PostRunSpeech => $this->postRunTitle($analysis),
            AnalysisType::MonthlyRecap => $this->monthlyRecapTitle($analysis),
            default => $meta['title'],
        };

        return trim($meta['emoji'] . ' ' . $phrase);
    }

    /** "Lari 8,2K kamu udah masuk! 🏁", dropping the distance when it's unknown. */
    private function postRunTitle(Analysis $analysis): string
    {
        $distance = $this->activityDetail($analysis->subject_id)?->distance;
        $prefix = $distance !== null ? $this->shortDistance((int) $distance) . ' ' : '';

        return 'Lari ' . $prefix . 'kamu udah masuk! 🏁';
    }

    /** "Rekap Juli udah siap", falling back to the label when the month is unknown. */
    private function monthlyRecapTitle(Analysis $analysis): string
    {
        $month = $this->monthName($analysis->discriminator);

        return $month === null ? self::TYPES[AnalysisType::MonthlyRecap->value]['title'] : "Rekap {$month} udah siap";
    }

    /** The Indonesian month name for a "YYYY-MM" discriminator, or null when blank. */
    private function monthName(?string $discriminator): ?string
    {
        if ($discriminator === null || $discriminator === '') {
            return null;
        }

        return self::MONTHS[Carbon::parse($discriminator . '-01')->month] ?? null;
    }

    /** Metres to a short "8,2K" label: km at 1 decimal, id comma, trailing ",0" dropped (5000 → "5K"). */
    private function shortDistance(int $meters): string
    {
        $rounded = number_format(round($meters / 1000, 1), 1, ',', '.');

        return rtrim(rtrim($rounded, '0'), ',') . 'K';
    }
}
