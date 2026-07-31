<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeAkuProfileVoiceJob;
use App\Jobs\AI\AnalyzeBaseJob;
use App\Jobs\AI\AnalyzeBriefingFeaturedKartuVoiceJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzeGroupJob;
use App\Jobs\AI\AnalyzeMonthlyRecapJob;
use App\Jobs\AI\AnalyzePrContextJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;

enum AnalysisType: string
{
    case BriefingMascotVoice = 'briefing_mascot_voice';
    case BriefingFeaturedKartuVoice = 'briefing_featured_kartu_voice';
    case PostRunSpeech = 'post_run_speech';
    case RunInsightTechnical = 'run_insight_technical';
    case RunInsightSplits = 'run_insight_splits';
    case RunInsightZones = 'run_insight_zones';
    case WeeklyRecap = 'weekly_recap';
    case PrContext = 'pr_context';
    case CardFlavor = 'card_flavor';
    case AkuProfileVoice = 'aku_profile_voice';
    case MonthlyRecap = 'monthly_recap';

    public const string BRIEFING_SUBJECT_TYPE = 'briefing_user_day';
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
            // The two briefing surfaces are intentionally NOT grouped — each
            // has its own row job so one of them retrying never re-bills the
            // other.
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
            self::BriefingMascotVoice,
            self::BriefingFeaturedKartuVoice => AnalysisCadence::Daily,
            self::WeeklyRecap => AnalysisCadence::Weekly,
            self::MonthlyRecap => AnalysisCadence::Monthly,
            self::AkuProfileVoice => AnalysisCadence::OnDemand,
        };
    }

    /** @return class-string<AnalyzeBaseJob> */
    public function jobClass(): string
    {
        return match ($this) {
            self::BriefingMascotVoice => AnalyzeBriefingMascotVoiceJob::class,
            self::BriefingFeaturedKartuVoice => AnalyzeBriefingFeaturedKartuVoiceJob::class,
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => AnalyzeActivityJob::class,
            self::WeeklyRecap => AnalyzeWeeklyRecapJob::class,
            self::PrContext => AnalyzePrContextJob::class,
            self::CardFlavor => AnalyzeCardFlavorJob::class,
            self::AkuProfileVoice => AnalyzeAkuProfileVoiceJob::class,
            self::MonthlyRecap => AnalyzeMonthlyRecapJob::class,
        };
    }

    /**
     * Connected + chained kinds: each item reads the previous same-kind
     * narrative and is narrated only after its chronological predecessor is
     * Done. A manual trigger on these resumes the earliest unfilled link of the
     * user's chain rather than narrating the clicked row in isolation, and only
     * the chain head may regenerate. See AnalysisController::trigger.
     *
     * WeeklyRecap + MonthlyRecap + the per-activity group (PostRunSpeech +
     * RunInsight*) are wired so far; a later slice flips BriefingMascotVoice on.
     */
    public function isChained(): bool
    {
        return match ($this) {
            self::WeeklyRecap,
            self::MonthlyRecap,
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => true,
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
            self::WeeklyRecap,
            self::MonthlyRecap => true,
            default => false,
        };
    }

    /**
     * Validation rules for a caller-supplied `discriminator` on this type, as a
     * closed set. The discriminator is part of the row identity and of the
     * re-trigger cooldown key, so an unconstrained value mints a fresh row and a
     * fresh billed generation on every request. Each arm mirrors the shape this
     * type's own dispatch sites write:
     *
     * - `Y-m-d` daily keys: DailyBriefingCommand, BriefingComposer.
     * - featured kartu: the RunCard id. Never null — BriefingComposer only emits
     *   the block once a card is picked, and a null id would bill the narrator's
     *   "no card yet" line under a second cooldown key.
     * - AkuProfileVoice: the ISO week key WeeklyProfileCommand + ProfileController use.
     * - `Y-m` months: MonthlyRecapCommand, CalendarController.
     * - every other type keys off subject_id alone and its job ignores the
     *   discriminator, so a non-null value is rejected outright.
     *
     * Exhaustive on purpose (no `default`): a new type must state its choice.
     *
     * @return list<string>
     */
    public function discriminatorRules(): array
    {
        return match ($this) {
            self::BriefingMascotVoice => ['required', 'string', 'date_format:Y-m-d'],
            self::BriefingFeaturedKartuVoice => ['required', 'string', 'max:19', 'regex:/^[1-9][0-9]*$/'],
            self::AkuProfileVoice => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/'],
            self::MonthlyRecap => ['required', 'string', 'date_format:Y-m'],
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones,
            self::WeeklyRecap,
            self::PrContext,
            self::CardFlavor => ['prohibited'],
        };
    }

    public function subjectType(): string
    {
        return match ($this) {
            self::BriefingMascotVoice,
            self::BriefingFeaturedKartuVoice => self::BRIEFING_SUBJECT_TYPE,
            self::PostRunSpeech,
            self::RunInsightTechnical,
            self::RunInsightSplits,
            self::RunInsightZones => Activity::class,
            self::WeeklyRecap => WeeklySnapshot::class,
            self::PrContext => PersonalRecord::class,
            self::CardFlavor => RunCard::class,
            self::AkuProfileVoice => self::AKU_PROFILE_VOICE_SUBJECT_TYPE,
            self::MonthlyRecap => self::MONTHLY_RECAP_SUBJECT_TYPE,
        };
    }

    public static function currentIsoWeek(): string
    {
        return Carbon::now()->isoFormat('GGGG-[W]WW');
    }
}
