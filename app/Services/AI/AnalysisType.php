<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeAkuProfileVoiceJob;
use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeBriefingFeaturedKartuVoiceJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeGroupJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePersonaSummaryJob;
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
    case BriefingMascotVoice = 'briefing_mascot_voice';
    case BriefingFeaturedKartuVoice = 'briefing_featured_kartu_voice';
    case PostRunSpeech = 'post_run_speech';
    case DailyGreeting = 'daily_greeting';
    case RunInsightTechnical = 'run_insight_technical';
    case RunInsightSplits = 'run_insight_splits';
    case RunInsightZones = 'run_insight_zones';
    case WeeklyRecap = 'weekly_recap';
    case PrContext = 'pr_context';
    case TrendCaption = 'trend_caption';
    case CardFlavor = 'card_flavor';
    case PersonaSummary = 'persona_summary';
    case AkuProfileVoice = 'aku_profile_voice';
    case MonthlyRecap = 'monthly_recap';

    public const string BRIEFING_SUBJECT_TYPE = 'briefing_user_day';
    public const string DAILY_GREETING_SUBJECT_TYPE = 'daily_greeting_user_day';
    public const string TREND_CAPTION_SUBJECT_TYPE = 'trend_caption_user_day';
    public const string PERSONA_SUMMARY_SUBJECT_TYPE = 'persona_summary_user';
    public const string AKU_PROFILE_VOICE_SUBJECT_TYPE = 'aku_profile_voice_user';
    public const string MONTHLY_RECAP_SUBJECT_TYPE = 'monthly_recap_user_month';

    /**
     * The multi-row group job this type is dispatched through (the whole group
     * is upserted + queued together), or null for single-row / on-demand types
     * dispatched individually. Single source of truth for grouping — both
     * {@see AnalyzeGroupJob::groupedTypes()} implementations and
     * AnalysisService derive from this.
     *
     * @return class-string<AnalyzeGroupJob>|null
     */
    public function groupJobClass(): ?string
    {
        return match ($this) {
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => AnalyzeActivityJob::class,
            // BriefingMascotVoice / BriefingFeaturedKartuVoice are intentionally
            // NOT grouped here — they split into their own jobs so the "Kata
            // Temari" / featured-card surfaces retry without re-billing the
            // headline + suggestion.
            self::BriefingHeadline,
            self::BriefingSuggestion => AnalyzeBriefingJob::class,
            default => null,
        };
    }

    /**
     * All analysis types dispatched through the given group job, in enum order.
     *
     * @param  class-string<AnalyzeGroupJob>  $groupJobClass
     * @return array<int, self>
     */
    public static function groupedBy(string $groupJobClass): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => $type->groupJobClass() === $groupJobClass,
        ));
    }

    /** How often this type is meant to (re)generate; governs cascade dispatch. */
    public function cadence(): AnalysisCadence
    {
        return match ($this) {
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones,
            self::CardFlavor,
            self::PrContext => AnalysisCadence::PerActivity,
            self::BriefingHeadline,
            self::BriefingSuggestion,
            self::BriefingMascotVoice,
            self::BriefingFeaturedKartuVoice,
            self::DailyGreeting,
            self::TrendCaption => AnalysisCadence::Daily,
            self::WeeklyRecap => AnalysisCadence::Weekly,
            self::MonthlyRecap => AnalysisCadence::Monthly,
            self::PersonaSummary,
            self::AkuProfileVoice => AnalysisCadence::OnDemand,
        };
    }

    /** @return class-string<AnalyzeBaseJob> */
    public function jobClass(): string
    {
        return match ($this) {
            self::BriefingHeadline,
            self::BriefingSuggestion => AnalyzeBriefingJob::class,
            self::BriefingMascotVoice => AnalyzeBriefingMascotVoiceJob::class,
            self::BriefingFeaturedKartuVoice => AnalyzeBriefingFeaturedKartuVoiceJob::class,
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => AnalyzeActivityJob::class,
            self::DailyGreeting => AnalyzeDailyGreetingJob::class,
            self::WeeklyRecap => AnalyzeWeeklyRecapJob::class,
            self::PrContext => AnalyzePrContextJob::class,
            self::TrendCaption => AnalyzeTrendCaptionJob::class,
            self::CardFlavor => AnalyzeCardFlavorJob::class,
            self::PersonaSummary => AnalyzePersonaSummaryJob::class,
            self::AkuProfileVoice => AnalyzeAkuProfileVoiceJob::class,
            self::MonthlyRecap => AnalyzeMonthlyRecapJob::class,
        };
    }

    public function isRuleBased(): bool
    {
        return match ($this) {
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones,
            self::TrendCaption => true,
            default => false,
        };
    }

    /**
     * Whether this narrative is derived from the user's heart-rate zones, so a
     * zone change makes copies generated beforehand stale (the "dihitung dengan
     * zona lama" hint). Zone-agnostic types never carry it.
     *
     * Only the zone breakdown ({@see self::RunInsightZones}) and the weekly
     * recap (zone-weighted TRIMP / CTL) read the configured zones. The technical
     * insight uses cadence, decoupling, the run's own peak HR, and elevation,
     * none of which move when zones change, so it is excluded.
     */
    public function isZoneDependent(): bool
    {
        return match ($this) {
            self::RunInsightZones,
            self::WeeklyRecap => true,
            default => false,
        };
    }

    public function subjectType(): string
    {
        return match ($this) {
            self::BriefingHeadline,
            self::BriefingSuggestion,
            self::BriefingMascotVoice,
            self::BriefingFeaturedKartuVoice => self::BRIEFING_SUBJECT_TYPE,
            self::TrendCaption => self::TREND_CAPTION_SUBJECT_TYPE,
            self::DailyGreeting => self::DAILY_GREETING_SUBJECT_TYPE,
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => Activity::class,
            self::WeeklyRecap => WeeklySnapshot::class,
            self::PrContext => PersonalRecord::class,
            self::CardFlavor => RunCard::class,
            self::PersonaSummary => self::PERSONA_SUMMARY_SUBJECT_TYPE,
            self::AkuProfileVoice => self::AKU_PROFILE_VOICE_SUBJECT_TYPE,
            self::MonthlyRecap => self::MONTHLY_RECAP_SUBJECT_TYPE,
        };
    }
}
