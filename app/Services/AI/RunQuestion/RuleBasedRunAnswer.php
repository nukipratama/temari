<?php

declare(strict_types=1);

namespace App\Services\AI\RunQuestion;

use App\Models\ActivityDetail;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\StreamSummary;

/**
 * The deterministic answer served to the demo account instead of an LLM call,
 * the Q&A analogue of {@see \App\Services\AI\RuleBased\RuleBasedNarrationFiller}.
 *
 * Every line is assembled from this run's own stored numbers, so the demo shows
 * a working feature without an anonymous visitor ever reaching Azure. It answers
 * the suggested questions, which is what the demo surfaces; free text that maps
 * to no suggestion gets this run's headline reading rather than a guess.
 */
final class RuleBasedRunAnswer
{
    public static function for(ActivityDetail $detail, string $question): string
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());
        $topic = RunQuestionSeeds::match($question, $detail);

        return match ($topic) {
            RunQuestionTopic::HrDrift => self::hrDrift($summary),
            RunQuestionTopic::Decoupling => self::decoupling($summary, $detail),
            RunQuestionTopic::NegativeSplit => self::negativeSplit($summary),
            RunQuestionTopic::CadenceDrop => self::cadenceDrop($summary, $detail),
            RunQuestionTopic::SlowestSplit => self::slowestSplit($summary),
            RunQuestionTopic::HardZones => self::hardZones($summary),
            RunQuestionTopic::Heat => self::heat($detail),
            RunQuestionTopic::Climb => self::climb($summary),
            RunQuestionTopic::Baseline, null => self::headline($detail),
        };
    }

    private static function hrDrift(StreamSummary $summary): string
    {
        $drift = self::oneDecimal($summary->hrDriftBpm() ?? 0.0);

        return "your heart rate climbed {$drift} bpm from the first half to the second while you held the pace. "
            .'that gap is the cost of the run, and it widens on the days you started tired or ran warm.';
    }

    private static function decoupling(StreamSummary $summary, ActivityDetail $detail): string
    {
        $pct = self::oneDecimal($summary->decouplingPct() ?? 0.0);
        $heat = ($detail->weather_temp_c ?? 0) >= 30
            ? " it was {$detail->weather_temp_c} degrees out, so a chunk of that is your body shedding heat rather than your base slipping."
            : ' cool conditions, so that one is about the base rather than the weather.';

        return "decoupling came in at {$pct}%, meaning your heart rate drifted up while pace stayed flat.".$heat;
    }

    private static function negativeSplit(StreamSummary $summary): string
    {
        $splits = $summary->perKm() ?? [];

        return 'you ran the back half quicker than the front, across '.count($splits).' km. '
            .'that only happens when the opening pace left something in reserve.';
    }

    private static function cadenceDrop(StreamSummary $summary, ActivityDetail $detail): string
    {
        $drop = self::oneDecimal($summary->cadenceDropSpm() ?? 0.0);
        $average = $detail->average_cadence !== null
            ? ' off an average of '.(int) round((float) $detail->average_cadence * 2).' steps a minute'
            : '';

        return "your step rate fell {$drop} steps a minute between the first half and the second{$average}. "
            .'shorter, slower steps late in a run usually mean the legs went before the lungs did.';
    }

    private static function slowestSplit(StreamSummary $summary): string
    {
        $slowestPace = null;
        $slowestKm = 0;

        foreach (array_values($summary->perKm() ?? []) as $index => $split) {
            $pace = is_string($split['pace'] ?? null) ? PaceFormatter::parse($split['pace']) : null;
            if ($pace !== null && ($slowestPace === null || $pace > $slowestPace)) {
                $slowestPace = $pace;
                $slowestKm = is_numeric($split['km'] ?? null) ? (int) $split['km'] : $index + 1;
            }
        }

        if ($slowestPace === null) {
            return 'the splits are all within a breath of each other. nothing on this one stands out as the expensive kilometre.';
        }

        return "km {$slowestKm} was your slowest at ".PaceFormatter::format($slowestPace).'/km. '
            .'that is where the run actually cost you something.';
    }

    private static function hardZones(StreamSummary $summary): string
    {
        $share = self::oneDecimal($summary->hardZoneShare());

        return "{$share}% of this run sat in Z3 or above. "
            .'that is a real chunk of the session spent above easy, whether or not you meant it that way.';
    }

    private static function heat(ActivityDetail $detail): string
    {
        $humidity = $detail->weather_humidity_pct !== null
            ? " at {$detail->weather_humidity_pct}% humidity"
            : '';

        return "it was {$detail->weather_temp_c} degrees{$humidity}. "
            .'heat like that buys you a higher heart rate for the same pace, so read this one as a warm-day effort, not a fitness reading.';
    }

    private static function climb(StreamSummary $summary): string
    {
        $grade = self::oneDecimal($summary->maxGradePct() ?? 0.0);
        $gap = $summary->gapPace();
        $adjusted = $gap !== null ? " flat-ground equivalent works out to {$gap}/km." : '';

        return "the steepest stretch hit {$grade}% grade.".$adjusted
            .' a climb takes its cut out of pace and hands most of it back on the way down.';
    }

    private static function headline(ActivityDetail $detail): string
    {
        if ($detail->distance === null) {
            return 'not much on the clock for this one. the numbers it does carry are on the run itself.';
        }

        $km = self::oneDecimal(DistanceFormatter::km($detail->distance));
        $pace = $detail->paceSecPerKm();
        $paceLabel = $pace !== null ? ' at '.PaceFormatter::format($pace).'/km' : '';

        return "{$km} km{$paceLabel} is what this one was. "
            .'that is the reading to hold the next one up against.';
    }

    private static function oneDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
