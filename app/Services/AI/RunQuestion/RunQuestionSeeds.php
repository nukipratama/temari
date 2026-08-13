<?php

declare(strict_types=1);

namespace App\Services\AI\RunQuestion;

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Which questions this run is worth asking, read off the run's own numbers.
 *
 * A summary-state run carries no streams, so most angles simply do not detect
 * and the list collapses to {@see RunQuestionTopic::Baseline}, which the
 * always-present history tools can answer. Nothing here ever offers a question
 * the data cannot support.
 */
final class RunQuestionSeeds
{
    /** Beyond this the suggestions stop being suggestions and become a menu. */
    private const int MAX_SEEDS = 4;

    /** Heart rate climbing by less than this over the run is noise, not drift. */
    private const float HR_DRIFT_BPM_FLOOR = 3.0;

    /** Step rate sagging by less than this is normal variation. */
    private const float CADENCE_DROP_SPM_FLOOR = 3.0;

    /** Share of moving time in Z3+ that makes "was this too hard?" a real question. */
    private const float HARD_ZONE_PCT_FLOOR = 20.0;

    /** Splits only become comparable once there are a few of them. */
    private const int MIN_SPLITS = 3;

    private const int HOT_TEMP_C = 30;

    private const float STEEP_GRADE_PCT = 5.0;

    /**
     * The topics this run supports, most notable first, capped at
     * {@see self::MAX_SEEDS}.
     *
     * @return list<RunQuestionTopic>
     */
    public static function for(ActivityDetail $detail): array
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());

        $detected = array_values(array_filter(
            RunQuestionTopic::cases(),
            fn (RunQuestionTopic $topic): bool => self::detects($topic, $detail, $summary),
        ));

        return array_slice($detected, 0, self::MAX_SEEDS);
    }

    /**
     * Whether this run carries a real reading behind $topic. Baseline is the
     * floor: comparing a run to the last 28 days needs nothing but the run.
     */
    public static function detects(RunQuestionTopic $topic, ActivityDetail $detail, StreamSummary $summary): bool
    {
        return match ($topic) {
            RunQuestionTopic::HrDrift => ($summary->hrDriftBpm() ?? 0.0) >= self::HR_DRIFT_BPM_FLOOR,
            RunQuestionTopic::Decoupling => $summary->hasDecouplingPct(),
            RunQuestionTopic::NegativeSplit => $summary->negativeSplit() === true,
            RunQuestionTopic::CadenceDrop => ($summary->cadenceDropSpm() ?? 0.0) >= self::CADENCE_DROP_SPM_FLOOR,
            RunQuestionTopic::SlowestSplit => count($summary->perKm() ?? []) >= self::MIN_SPLITS,
            RunQuestionTopic::HardZones => $summary->hardZoneShare() >= self::HARD_ZONE_PCT_FLOOR,
            RunQuestionTopic::Heat => ($detail->weather_temp_c ?? 0) >= self::HOT_TEMP_C,
            RunQuestionTopic::Climb => ($summary->maxGradePct() ?? 0.0) >= self::STEEP_GRADE_PCT,
            RunQuestionTopic::Baseline => true,
        };
    }

    /**
     * The topic a question was asked about, matched against the suggestions this
     * run offered. Free text that matches nothing returns null.
     */
    public static function match(string $question, ActivityDetail $detail): ?RunQuestionTopic
    {
        $normalised = self::normalise($question);

        foreach (self::for($detail) as $topic) {
            if (self::normalise($topic->question()) === $normalised) {
                return $topic;
            }
        }

        return null;
    }

    private static function normalise(string $question): string
    {
        return mb_strtolower(trim($question, " \t\n\r\0\x0B?"));
    }
}
