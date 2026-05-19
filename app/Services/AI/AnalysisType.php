<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzePrContextJob;
use App\Jobs\AI\AnalyzeTrendCaptionJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;

enum AnalysisType: string
{
    case BriefingHeadline = 'briefing_headline';
    case BriefingSuggestion = 'briefing_suggestion';
    case PostRunSpeech = 'post_run_speech';
    case DailyGreeting = 'daily_greeting';
    case RunInsightTechnical = 'run_insight_technical';
    case RunInsightSplits = 'run_insight_splits';
    case RunInsightZones = 'run_insight_zones';
    case WeeklyRecap = 'weekly_recap';
    case PrContext = 'pr_context';
    case TrendCaption = 'trend_caption';
    case CardFlavor = 'card_flavor';

    public const string BRIEFING_SUBJECT_TYPE = 'briefing_user_day';
    public const string DAILY_GREETING_SUBJECT_TYPE = 'daily_greeting_user_day';
    public const string TREND_CAPTION_SUBJECT_TYPE = 'trend_caption_user_day';

    /** @return class-string<AnalyzeBaseJob> */
    public function jobClass(): string
    {
        return match ($this) {
            self::BriefingHeadline, self::BriefingSuggestion => AnalyzeBriefingJob::class,
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => AnalyzeActivityJob::class,
            self::DailyGreeting => AnalyzeDailyGreetingJob::class,
            self::WeeklyRecap => AnalyzeWeeklyRecapJob::class,
            self::PrContext => AnalyzePrContextJob::class,
            self::TrendCaption => AnalyzeTrendCaptionJob::class,
            self::CardFlavor => AnalyzeCardFlavorJob::class,
        };
    }

    public function subjectType(): string
    {
        return match ($this) {
            self::BriefingHeadline, self::BriefingSuggestion => self::BRIEFING_SUBJECT_TYPE,
            self::TrendCaption => self::TREND_CAPTION_SUBJECT_TYPE,
            self::DailyGreeting => self::DAILY_GREETING_SUBJECT_TYPE,
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => Activity::class,
            self::WeeklyRecap => WeeklySnapshot::class,
            self::PrContext => PersonalRecord::class,
            self::CardFlavor => RunCard::class,
        };
    }
}
