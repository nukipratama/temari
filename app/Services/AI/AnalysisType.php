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
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\AI\AnalyzePlanWeekVoiceJob;
use App\Jobs\AI\AnalyzePrContextJob;
use App\Jobs\AI\AnalyzeTrendReadJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\PlanAdaptation;
use App\Models\RunCard;
use App\Models\Season;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

enum AnalysisType: string
{
    case BriefingMascotVoice = 'briefing_mascot_voice';
    case BriefingFeaturedKartuVoice = 'briefing_featured_kartu_voice';
    case PostRunSpeech = 'post_run_speech';
    case RunInsight = 'run_insight';
    case WeeklyRecap = 'weekly_recap';
    case PrContext = 'pr_context';
    case CardFlavor = 'card_flavor';
    case AkuProfileVoice = 'aku_profile_voice';
    case MonthlyRecap = 'monthly_recap';
    case TrendRead = 'trend_read';
    case PlanDayVoice = 'plan_day_voice';
    case PlanWeekVoice = 'plan_week_voice';
    case PlanSeasonVoice = 'plan_season_voice';

    public const string BRIEFING_SUBJECT_TYPE = 'briefing_user_day';
    public const string AKU_PROFILE_VOICE_SUBJECT_TYPE = 'aku_profile_voice_user';
    public const string MONTHLY_RECAP_SUBJECT_TYPE = 'monthly_recap_user_month';
    public const string TREND_READ_SUBJECT_TYPE = 'trend_read_user_range';
    public const string PLAN_DAY_VOICE_SUBJECT_TYPE = 'plan_day_voice_user_day';

    /**
     * The three windows Trends narrates. Not chained, not date-keyed — each is
     * always "as of now", so the discriminator names the range, not a period.
     *
     * @var list<string>
     */
    public const array TREND_READ_RANGES = ['30d', '90d', '12mo'];

    /**
     * How far back a period-keyed discriminator may reach. Deliberately wider
     * than `ai.backfill_max_age_days` so the two bounds stay distinct: this one
     * closes the set of rows a caller can mint, the narration cutoff decides
     * which of them are worth an LLM call.
     */
    public const int MAX_DISCRIMINATOR_AGE_DAYS = 365;

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
            self::RunInsight => AnalyzeActivityJob::class,
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
            self::RunInsight,
            self::CardFlavor,
            self::PrContext => AnalysisCadence::PerActivity,
            self::BriefingMascotVoice,
            self::BriefingFeaturedKartuVoice,
            self::PlanDayVoice => AnalysisCadence::Daily,
            self::WeeklyRecap,
            self::PlanWeekVoice => AnalysisCadence::Weekly,
            self::MonthlyRecap => AnalysisCadence::Monthly,
            // Neither AkuProfileVoice nor TrendRead is cascade-dispatched
            // from post-run ingest, both have their own separate scheduled
            // command(s) instead. TrendRead actually runs three different
            // cadences (one per range — see routes/console.php), which no
            // single case here represents. PlanSeasonVoice changes only at
            // season boundaries (a race set/cleared, or a self-scaled
            // season's 12-week expiry), not on any fixed clock.
            self::AkuProfileVoice,
            self::TrendRead,
            self::PlanSeasonVoice => AnalysisCadence::OnDemand,
        };
    }

    /** @return class-string<AnalyzeBaseJob> */
    public function jobClass(): string
    {
        return match ($this) {
            self::BriefingMascotVoice => AnalyzeBriefingMascotVoiceJob::class,
            self::BriefingFeaturedKartuVoice => AnalyzeBriefingFeaturedKartuVoiceJob::class,
            self::PostRunSpeech,
            self::RunInsight => AnalyzeActivityJob::class,
            self::WeeklyRecap => AnalyzeWeeklyRecapJob::class,
            self::PrContext => AnalyzePrContextJob::class,
            self::CardFlavor => AnalyzeCardFlavorJob::class,
            self::AkuProfileVoice => AnalyzeAkuProfileVoiceJob::class,
            self::MonthlyRecap => AnalyzeMonthlyRecapJob::class,
            self::TrendRead => AnalyzeTrendReadJob::class,
            self::PlanDayVoice => AnalyzePlanDayVoiceJob::class,
            self::PlanWeekVoice => AnalyzePlanWeekVoiceJob::class,
            self::PlanSeasonVoice => AnalyzePlanSeasonVoiceJob::class,
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
     * RunInsight) are wired so far; a later slice flips BriefingMascotVoice on.
     */
    public function isChained(): bool
    {
        return match ($this) {
            self::WeeklyRecap,
            self::MonthlyRecap,
            self::PostRunSpeech,
            self::RunInsight => true,
            default => false,
        };
    }

    /**
     * Whether this narrative is derived from the user's heart-rate zones, so a
     * zone change makes copies generated beforehand stale (the "calculated with old zones" hint). Zone-agnostic types never carry it.
     *
     * RunInsight's claims are a variable mix (a `zone:<z>` claim reads the
     * configured zones, a `split:<n>`/other `metric:*` claim does not), and the
     * row carries no flag saying which shape it landed on, so it is treated as
     * zone-dependent unconditionally: a false "stale" hint on a zone-free row
     * costs nothing, a missed one on a zone-anchored row would silently show
     * numbers computed under zones the user has since changed.
     */
    public function isZoneDependent(): bool
    {
        return match ($this) {
            self::RunInsight,
            self::WeeklyRecap,
            self::MonthlyRecap,
            // Narrates monotony/strain/CTL movement, all TRIMP-derived and
            // therefore zone-weighted, same reasoning as WeeklyRecap/MonthlyRecap.
            self::TrendRead => true,
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
     * - `Y-m` months: MonthlyRecapCommand, HistoryController.
     * - every other type keys off subject_id alone and its job ignores the
     *   discriminator, so a non-null value is rejected outright.
     *
     * A shape rule alone is not a closed set: `date_format:Y-m-d` admits some
     * 3.6M days, so a caller could mint that many permanent rows. The
     * period-keyed arms therefore carry a range as well as a shape, and the one
     * arm naming a *resource* is ownership-checked in
     * {@see AnalysisSubjectAuthorizer} instead, where a 403 is the honest answer.
     *
     * Exhaustive on purpose (no `default`): a new type must state its choice.
     *
     * @return list<string|In>
     */
    public function discriminatorRules(): array
    {
        return match ($this) {
            self::BriefingMascotVoice => [
                'required', 'string', 'date_format:Y-m-d',
                'after_or_equal:'.Carbon::today()->subDays(self::MAX_DISCRIMINATOR_AGE_DAYS)->toDateString(),
                'before_or_equal:'.Carbon::today()->toDateString(),
            ],
            self::BriefingFeaturedKartuVoice => ['required', 'string', 'max:19', 'regex:/^[1-9][0-9]*$/'],
            self::AkuProfileVoice => ['required', 'string', 'regex:/^\d{4}-W\d{2}$/', Rule::in(self::triggerableIsoWeeks())],
            self::MonthlyRecap => ['required', 'string', 'date_format:Y-m', Rule::in(self::triggerableMonths())],
            self::TrendRead => ['required', 'string', Rule::in(self::TREND_READ_RANGES)],
            self::PlanDayVoice => [
                'required', 'string', 'date_format:Y-m-d',
                'after_or_equal:'.Carbon::today()->subDays(self::MAX_DISCRIMINATOR_AGE_DAYS)->toDateString(),
                // The current week's 7 days can include future dates (Tue asking
                // for Saturday's blurb) — bounded to a week out rather than
                // "today" the way BriefingMascotVoice is, since plan narration is
                // never about the current moment alone.
                'before_or_equal:'.Carbon::today()->addDays(7)->toDateString(),
            ],
            self::PostRunSpeech,
            self::RunInsight,
            self::WeeklyRecap,
            self::PrContext,
            self::CardFlavor,
            self::PlanWeekVoice,
            self::PlanSeasonVoice => ['prohibited'],
        };
    }

    public function subjectType(): string
    {
        return match ($this) {
            self::BriefingMascotVoice,
            self::BriefingFeaturedKartuVoice => self::BRIEFING_SUBJECT_TYPE,
            self::PostRunSpeech,
            self::RunInsight => Activity::class,
            self::WeeklyRecap => WeeklySnapshot::class,
            self::PrContext => PersonalRecord::class,
            self::CardFlavor => RunCard::class,
            self::AkuProfileVoice => self::AKU_PROFILE_VOICE_SUBJECT_TYPE,
            self::MonthlyRecap => self::MONTHLY_RECAP_SUBJECT_TYPE,
            self::TrendRead => self::TREND_READ_SUBJECT_TYPE,
            self::PlanDayVoice => self::PLAN_DAY_VOICE_SUBJECT_TYPE,
            self::PlanWeekVoice => PlanAdaptation::class,
            self::PlanSeasonVoice => Season::class,
        };
    }

    public static function currentIsoWeek(): string
    {
        return Carbon::now()->isoFormat('GGGG-[W]WW');
    }

    /**
     * The ISO weeks a manual trigger may name. The profile narrator reads a
     * rolling window as of now and ignores the week key entirely, so only the
     * current week is meaningful; the previous one is admitted so a page loaded
     * just before a week rollover can still retry.
     *
     * @return list<string>
     */
    private static function triggerableIsoWeeks(): array
    {
        return [
            Carbon::now()->subWeek()->isoFormat('GGGG-[W]WW'),
            self::currentIsoWeek(),
        ];
    }

    /**
     * The months a manual trigger may name, current back to the age cap. The
     * calendar can page further, but the recap is chained: a click on an older
     * month resumes the chain forward rather than narrating that month, so the
     * bound costs no reachable behaviour.
     *
     * @return list<string>
     */
    private static function triggerableMonths(): array
    {
        $current = Carbon::today()->startOfMonth();

        return array_map(
            static fn (int $monthsBack): string => $current->copy()->subMonthsNoOverflow($monthsBack)->format('Y-m'),
            range(0, intdiv(self::MAX_DISCRIMINATOR_AGE_DAYS, 30)),
        );
    }
}
