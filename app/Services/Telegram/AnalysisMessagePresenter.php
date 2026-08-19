<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;
use Illuminate\Support\Carbon;

/**
 * Builds the Telegram message body and web-push title/URL for an already-
 * eligible analysis. See {@see NotificationEligibility} for whether the
 * analysis should be sent at all.
 */
class AnalysisMessagePresenter
{
    /**
     * Month names by month number, for the monthly-recap title. Hardcoded
     * rather than leaning on Carbon's locale data, which isn't guaranteed
     * loaded in every runtime.
     *
     * @var array<int, string>
     */
    private const array MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * Per-instance memo of activity_id => ActivityDetail, so a single message
     * build (the metrics line + the post-run title both look up the same row)
     * hits the DB once.
     *
     * @var array<int, ActivityDetail|null>
     */
    private array $detailCache = [];

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

        $meta = NotifiableAnalysisTypes::TYPES[$analysis->analysis_type->value] ?? null;
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
            $parts[] = DistanceFormatter::kmString($detail->distance) . ' km';
        }
        if ($detail->elapsed_time !== null) {
            $parts[] = DurationFormatter::hms($detail->elapsed_time);
        }
        $pace = PaceCalculator::secPerKm($detail->distance, $detail->elapsed_time);
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

    /** Absolute app URL the notification links to, or null when not resolvable. */
    public function url(Analysis $analysis): ?string
    {
        return match ($analysis->analysis_type) {
            AnalysisType::PostRunSpeech => route('activities.show', $analysis->subject_id),
            AnalysisType::WeeklyRecap => $this->weeklyRecapUrl($analysis),
            AnalysisType::MonthlyRecap => route('history', ['view' => 'calendar', 'month' => $analysis->discriminator]),
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
            ? route('history')
            : route('history', ['week' => Carbon::parse($weekEnding)->toDateString()]);
    }

    /**
     * The notification title shared by web push and the Telegram body's first
     * line: a short, data-aware phrase (run distance, recap month). Falls back
     * to the type's data-less label when that data can't be resolved, and to
     * the app name for an unregistered type.
     */
    public function title(Analysis $analysis): string
    {
        $meta = NotifiableAnalysisTypes::TYPES[$analysis->analysis_type->value] ?? null;
        if ($meta === null) {
            return 'Temari';
        }

        $phrase = match ($analysis->analysis_type) {
            AnalysisType::PostRunSpeech => $this->postRunTitle($analysis),
            AnalysisType::MonthlyRecap => $this->monthlyRecapTitle($analysis),
            default => $meta['title'],
        };

        return $phrase;
    }

    /** "Your 8.2K run is in.", dropping the distance when it's unknown. */
    private function postRunTitle(Analysis $analysis): string
    {
        $distance = $this->activityDetail($analysis->subject_id)?->distance;
        $prefix = $distance !== null ? $this->shortDistance((int) $distance) . ' ' : '';

        return 'Your ' . $prefix . 'run is in.';
    }

    /** "Your July recap is ready", falling back to the label when the month is unknown. */
    private function monthlyRecapTitle(Analysis $analysis): string
    {
        $month = $this->monthName($analysis->discriminator);

        return $month === null ? NotifiableAnalysisTypes::TYPES[AnalysisType::MonthlyRecap->value]['title'] : "Your {$month} recap is ready";
    }

    /** The month name for a "YYYY-MM" discriminator, or null when blank. */
    private function monthName(?string $discriminator): ?string
    {
        if ($discriminator === null || $discriminator === '') {
            return null;
        }

        return self::MONTHS[Carbon::parse($discriminator . '-01')->month] ?? null;
    }

    /** Metres to a short "8,2K" label: km at 1 decimal, trailing ",0" dropped (5000 → "5K"). */
    private function shortDistance(int $meters): string
    {
        return DecimalFormatter::trimmed(DistanceFormatter::km((float) $meters)) . 'K';
    }
}
