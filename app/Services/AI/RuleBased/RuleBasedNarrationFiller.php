<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use Illuminate\Support\Collection;
use App\Enums\Badge;
use App\Enums\IntentVerdict;
use App\Enums\PlannedSessionStatus;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\Narrators\NarrationContent;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\IntentOutcome;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TimeTrial;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use LogicException;
use App\Services\Run\Metrics\TrainingFormStatus;

/**
 * Rule-based content per AnalysisType. Reached in production, not only by the demo seed:
 *
 * - {@see \App\Listeners\DispatchPostRunAnalysis} for material past `ai.backfill_max_age_days`, and
 *   {@see \App\Services\AI\BackfillAgeGate} for a manual retry on the same
 * - {@see \App\Jobs\AI\AnalyzeRowJob} / {@see \App\Jobs\AI\AnalyzeGroupJob} when Azure
 *   content-filters a generation twice over
 * - the public demo account's triggers, and DemoSeedCommand's backfill
 *
 * Output is deterministic and Temari-voiced. Where the subject's real data is available it drives
 * the copy (the post-run speech's claims via {@see RuleBasedRunInsights}), falling back to seeded
 * variants only when the subject row is missing.
 */
final readonly class RuleBasedNarrationFiller
{
    /** Recap volume bands, as a ratio of the period's distance to the athlete's usual volume. */
    private const float LIGHT_VOLUME_RATIO = 0.5;

    private const float BIG_VOLUME_RATIO = 1.5;

    /** @var non-empty-list<string> */
    private const array POST_RUN_BRIEFINGS = [
        "{distance_line}{verdict_line}.\n\nThat's the session accounted for, so the rest of today is recovery. Drink, eat something decent, and keep the legs out of another session.\n\nIf they still feel loaded, make mobility gentle and call it there.",
        "{distance_line}{verdict_line}.\n\nNo second effort today. Recovery is the move; let the work settle, get some food and water in, and leave the scoreboard alone for a bit.\n\nHeavy legs or an odd HR response later means the quiet option wins.",
        "{distance_line}{verdict_line}.\n\nToday's work is done. Recovery is the useful move now: fluids, food, and nothing heroic added to the log.\n\nIf the body stays louder than the numbers suggest, keep the rest of the day easy.",
    ];

    public function __construct(
        private TrainingBaseline $trainingBaseline,
    ) {
    }

    public function fillFor(Analysis $row): string
    {
        $seed = $this->seedFor($row);

        return match ($row->analysis_type) {
            AnalysisType::BriefingMascotVoice => $this->briefingMascotVoice($row, $seed),
            AnalysisType::PostRunSpeech => $this->postRunSpeech($seed),
            AnalysisType::RunInsight => throw new LogicException('RunInsight is retired and is never narrated.'),
            AnalysisType::WeeklyRecap => $this->weeklyRecap($seed),
            AnalysisType::CardFlavor => $this->cardFlavor($seed),
            AnalysisType::ProfileVoice => $this->profileVoice($seed),
            AnalysisType::MonthlyRecap => $this->monthlyRecap($row->subject_id, $row->discriminator, $seed),
            AnalysisType::TrendRead => $this->trendRead($seed),
            AnalysisType::PlanClampVoice => $this->planClampVoice($seed),
        };
    }

    public function postRunBriefing(User $user, Carbon $date): ?string
    {
        $date = $date->toDateString();

        return $this->postRunBriefingFor($user->id, $date, $user->id + crc32($date));
    }

    /**
     * Deterministic selection seed for a row. The discriminator (when present)
     * is folded in so discriminator-keyed types (monthly/weekly recap, daily
     * briefing) produce distinct content per discriminator instead of repeating
     * the same subject-only variant. A null discriminator leaves the seed equal
     * to subject_id, preserving determinism for non-discriminated types.
     */
    private function seedFor(Analysis $row): int
    {
        if ($row->discriminator === null) {
            return $row->subject_id;
        }

        return $row->subject_id + crc32($row->discriminator);
    }


    /**
     * The clamp's templated note is a permanent floor on the card, so this is
     * never the difference between an explanation and none — it stands in when
     * the model is paused or the day is cost-capped, and says the one thing the
     * note cannot: that the step-down has a reason behind it.
     */
    private function planClampVoice(int $seed): string
    {
        return $this->select([
            'Load has been stacking up. Today gives it somewhere to go.',
            "you've been carrying a lot this week, so today steps back a little.",
            'a heavier stretch than usual, so this one comes down a notch.',
            'Nothing wrong here, just a heavy week. Easy keeps it intact.',
        ], $seed);
    }

    private function briefingMascotVoice(Analysis $row, int $seed): string
    {
        return $this->postRunBriefingFor($row->subject_id, $row->discriminator, $seed) ?? $this->select([
            "easy tempo, 35-45 minutes.\n\nNothing quality has gone into the log since last week and your rhythm's been flat and steady the whole time, so today's the day to break that up. 10 minutes easy to warm up, 15-20 minutes a bit quicker than your usual pace, then cool down. Cadence 175+.\n\nWhat to watch: if HR climbs fast at easy pace, drop it to a 15-25 minute run-walk and stop at the cooldown. Brutal heat is reason enough to run the whole thing easy instead.",
            "easy run, 30-40 minutes.\n\nYour last two sessions both read heavy and the gap since then is short, so today is easy, and I mean actually easy. Hold your normal pace, breathing loose enough to talk, cadence 170+ so the steps stay light.\n\nWhat to watch: legs still heavy or HR up early means the recovery isn't finished. A brisk 20-minute walk covers the day.",
            "long run, 8-12 km easy.\n\nYour weekly distance has crept up every week this month, and this is the session that closes it out. Conversational pace the whole way, don't go chasing a time, take water if it's hot.\n\nWhat to watch: if km 5 already feels like work, cut it at 6-8. Short and clean beats long and ugly.",
            "rest today.\n\nYou've run every day of this stretch and the load is stacked higher than anything you've been carrying. No run from me today. Light mobility, or a 20-minute walk if you want the legs moving.\n\nWhat to watch: still heavy tomorrow, take another one. A zero today is what keeps the rest of the week.",
            "easy run, 25-30 minutes.\n\nFirst one back after a few days off, so this one is short on purpose. Slow, effort by feel, don't look at the pace at all.\n\nWhat to watch: the classic move after time off is picking up exactly where you left off. If the breathing gets heavy before minute 15, ease off or walk a bit.",
            "base run, 5-7 km.\n\nYou've hit every session you meant to this week and nothing in the numbers says back off, so today just holds the rhythm that's already working. Your average pace, one steady block, no gear changes.\n\nWhat to watch: a run like this turns into a tempo halfway through if you let it. Save the push for a day it's actually on the plan.",
        ], $seed);
    }

    private function postRunBriefingFor(int $userId, ?string $date, int $seed): ?string
    {
        if ($date === null) {
            return null;
        }

        $activities = Activity::query()
            ->where('user_id', $userId)
            ->detailed()
            ->whereHas('detail', fn ($query) => $query->startedOn($date))
            ->with('detail')
            ->get();
        if ($activities->isEmpty()) {
            return null;
        }

        $distancesM = $activities->map(
            fn (Activity $activity): float => (float) $activity->detail?->distance,
        );
        $actualMeters = $distancesM->sum();
        assert(is_float($actualMeters) || is_int($actualMeters));
        $actualKm = DistanceFormatter::km($actualMeters);
        $actual = DistanceFormatter::kmString($actualMeters) ?? '0.0';
        $planned = PlannedSession::query()
            ->where('user_id', $userId)
            ->where('date', $date)
            ->first();
        $plannedKm = $planned?->prescribed_km;
        if ($planned !== null && $plannedKm === null) {
            $baseline = $this->trainingBaseline->forUser($planned->user, $planned->date);
            $plannedKm = EffectiveSession::of(
                $planned,
                PlanRenderer::coreKmForSession(
                    $planned,
                    $baseline['long_run_km'],
                    $baseline['long_run_cap_km'],
                    $baseline['self_scaled'],
                    $baseline['long_run_progression_cap_km'],
                ),
            )->coreKm;
        }
        $creditedKm = $planned === null ? null : round(SessionMatcher::creditedKm($planned->session_type, [
            'sum' => DistanceFormatter::km($actualMeters),
            'longest' => DistanceFormatter::km((float) $distancesM->max()),
        ], TimeTrial::of($planned) instanceof TimeTrial), 1);
        $distanceLine = match (true) {
            $plannedKm === null => "{$actual} km logged, no plan for today",
            $plannedKm <= 0 => "{$actual} km logged on a planned rest day",
            $creditedKm !== null && $creditedKm !== $actualKm => "{$actual} km logged, ".(DistanceFormatter::kmString($creditedKm * 1000) ?? '?').' km credited against '.(DistanceFormatter::kmString($plannedKm * 1000) ?? '?').' km planned',
            default => "{$actual} km done against ".(DistanceFormatter::kmString($plannedKm * 1000) ?? '?').' km planned',
        };
        $status = $planned?->status;
        $intent = $planned?->intent_verdict;
        $distanceStatus = match (true) {
            $plannedKm !== null && $plannedKm <= 0 => null,
            $status === PlannedSessionStatus::Planned, $status === PlannedSessionStatus::Skip => null,
            $intent === IntentVerdict::Missed => null,
            $status === PlannedSessionStatus::Overreached && ($planned->distance_score === null
                || $planned->distance_score < SessionMatcher::OVERREACHED_FRACTION * 100) => null,
            default => $status,
        };
        $verdictLine = implode(', ', array_filter([
            $distanceStatus === null
                ? ($planned?->distance_score === null ? null : 'distance '.$planned->distance_score.'% of planned')
                : 'distance '.$this->postRunStatus($distanceStatus),
            $intent === null ? null : IntentOutcome::outcome($intent, $planned->intent_evidence ?? []),
        ]));

        return strtr($this->select(self::POST_RUN_BRIEFINGS, $seed), [
            '{distance_line}' => $distanceLine,
            '{verdict_line}' => $verdictLine === '' ? '' : ', '.$verdictLine,
        ]);
    }

    private function postRunStatus(PlannedSessionStatus $status): string
    {
        return $status === PlannedSessionStatus::Done ? 'met' : $status->value;
    }

    private function detailFor(int $activityId): ?ActivityDetail
    {
        return ActivityDetail::query()->where('activity_id', $activityId)->first();
    }

    private function postRunSpeech(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if (! $detail instanceof ActivityDetail) {
            return "Done. That one's in the log.";
        }

        return new NarrationContent(null, $this->postRunSpeechFor($detail, $activityId), RuleBasedRunInsights::claims($detail))->toStored();
    }

    public function postRunSpeechFor(ActivityDetail $detail, int $activityId): string
    {
        $km = DistanceFormatter::kmString($detail->distance) ?? '?';

        // Two slots under separate salts: the History feed shows these side by
        // side, and one pool of whole sentences repeats verbatim within a screen.
        $opener = strtr($this->select(self::POST_RUN_OPENERS, crc32('post_run_speech_open_'.$activityId)), ['{km}' => $km]);
        $closer = $this->select(self::POST_RUN_CLOSERS, crc32('post_run_speech_close_'.$activityId));

        return $opener.' '.$closer.$this->postRunCoda($detail, $activityId);
    }

    /**
     * One sentence each, carrying `{km}`, so any opener reads ahead of any closer.
     *
     * @var non-empty-list<string>
     */
    private const array POST_RUN_OPENERS = [
        '{km} km, logged.',
        '{km} km on the board.',
        'Another {km} km on the pile.',
        '{km} km today.',
        "Today's line in the log: {km} km.",
        '{km} km down.',
        '{km} km, done and filed.',
        "That's {km} km in the book.",
        '{km} km, start to finish.',
        '{km} km, out the door and back.',
        "{km} km, and the week's total just moved.",
        'You put {km} km away.',
        '{km} km, no more and no less.',
        'The log reads {km} km.',
        '{km} km on the watch.',
    ];

    /**
     * None of these claim anything about how the run went, so no pairing can
     * contradict its opener or the data-driven coda after it.
     *
     * @var non-empty-list<string>
     */
    private const array POST_RUN_CLOSERS = [
        'How did the legs feel by the end of that one?',
        "The week's shape gets built out of these.",
        'Not every run needs a story attached.',
        'Consistency is mostly this, repeated.',
        "Whatever it felt like, it's counted now.",
        "The ones you don't remember still add up.",
        'Nothing to fix here, so nothing from me.',
        'No notes.',
        "That's the kind that stacks quietly.",
        "The log doesn't care how it felt, but I do a bit.",
        'A run like this is the base, not the highlight.',
        'Nothing here needs untangling.',
        'On to the next one.',
        'Ordinary is doing most of the work here.',
        'File it and move on.',
        'You know how that one went better than I do.',
    ];

    /**
     * One short data-driven coda for the post-run line, picked from whichever
     * real signal the run actually carries (negative split / heat / rain),
     * seeded so a given run always reads the same. Empty when nothing stands out.
     */
    private function postRunCoda(ActivityDetail $detail, int $seed): string
    {
        $codas = [];
        if (StreamSummary::fromArray($detail->stream_summary)->negativeSplit() === true) {
            $codas[] = ' Second half actually got faster, which is the harder way to run it.';
        }
        if ($detail->weather_rain_detected === true) {
            $codas[] = ' In the rain, too.';
        } elseif ($detail->ranHot()) {
            $codas[] = " That was at {$detail->weather_temp_c} degrees, so it cost more than the pace lets on.";
        }

        return $codas === [] ? '' : $codas[abs($seed) % count($codas)];
    }


    private function weeklyRecap(int $snapshotId): string
    {
        $snapshot = WeeklySnapshot::query()->find($snapshotId);
        if ($snapshot === null || $snapshot->runs === null || $snapshot->runs < 1) {
            return $this->select([
                "Nothing in the log this week. A gap is a gap, I'm not going to call it anything else.",
                'A blank week. The counter sits at zero and next week starts from there.',
                'Quiet week. The log stayed exactly where you left it.',
                "No runs this week. I'm not dressing that up.",
                'Empty week. Easing back in gets you further than trying to win it all back in one session.',
                "This week didn't get a run in it. The next one is still open.",
                'No entries this week. Starting small again gets you back quicker than starting big.',
            ], $snapshotId);
        }

        $km = DecimalFormatter::decimal((float) $snapshot->distance_km);
        $runs = $snapshot->runs;
        $runWord = $runs === 1 ? 'run' : 'runs';
        $sessionWord = $runs === 1 ? 'session' : 'sessions';
        $timeWord = $runs === 1 ? 'time' : 'times';

        if ($snapshot->form_status === null && $snapshot->weekly_trimp !== null && $this->formKnownBy($snapshot)) {
            Log::warning('narrator.recap.form_status_missing', [
                'snapshot_id' => $snapshotId,
                'runs' => $runs,
            ]);
        }

        $closer = match ($snapshot->form_status) {
            TrainingFormStatus::Fresh => "Your recent running is lighter than your longer-term load, so there's room to add a little.",
            TrainingFormStatus::Optimal => match ($this->volumeBand((float) $snapshot->distance_km, $this->usualWeeklyKm($snapshot))) {
                'light' => "Well below your usual week. That's the number, not a verdict on it.",
                'big' => "Well above your usual week, and your load balance is still steady.",
                default => "Right around your usual week.",
            },
            TrainingFormStatus::Fatigued, TrainingFormStatus::Overreaching => "Your recent running is above your longer-term load, which is normal in a build week. If you feel run down, illness, poor sleep or under-fuelling can be behind it too.",
            default => "Steady. That's the read.",
        };

        return $this->select([
            "{$km} km across {$runs} {$runWord} this week. {$closer}",
            "{$runs} {$sessionWord}, {$km} km on the board. {$closer}",
            "{$km} km this week, spread over {$runs} {$runWord}. {$closer}",
            "{$runs} {$runWord}, {$km} km. {$closer}",
            "The week came to {$km} km from {$runs} {$sessionWord}. {$closer}",
            "{$km} km logged, {$runs} {$timeWord} out the door. {$closer}",
        ], $snapshotId);
    }

    /**
     * Whether the week closes on or after the day {@see TrainingLoad} starts
     * reading form: one CTL time constant after the athlete's first scored run.
     */
    private function formKnownBy(WeeklySnapshot $snapshot): bool
    {
        $firstScored = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $snapshot->user_id)
            ->whereNotNull('activity_details.trimp_edwards')
            ->min('activity_details.start_date_local');
        assert($firstScored === null || is_string($firstScored));

        return $firstScored !== null
            && $snapshot->week_ending->toDateString() >= Carbon::parse($firstScored)->addDays(TrainingLoad::CTL_TAU)->toDateString();
    }

    /**
     * The athlete's own usual week, as a trailing mean of up to 6 prior weeks'
     * distance — the same depth {@see \App\Services\Run\Plan\TrainingBaseline}
     * trails. Null with no prior weeks to compare against.
     */
    private function usualWeeklyKm(WeeklySnapshot $snapshot): ?float
    {
        /** @var Collection<int, float|null> $priorDistances */
        $priorDistances = WeeklySnapshot::query()
            ->where('user_id', $snapshot->user_id)
            ->where('week_ending', '<', $snapshot->week_ending)
            ->orderByDesc('week_ending')
            ->limit(6)
            ->pluck('distance_km');
        $distances = $priorDistances->filter(fn (?float $km): bool => $km !== null);

        return $distances->isEmpty() ? null : (float) $distances->avg();
    }

    /**
     * light: under half the usual week. big: over 1.5x it. Anything in
     * between, or no usual week to compare against, reads as usual.
     */
    private function volumeBand(float $km, ?float $usualKm): string
    {
        if ($usualKm === null || $usualKm <= 0.0) {
            return 'usual';
        }

        $ratio = $km / $usualKm;

        return match (true) {
            $ratio < self::LIGHT_VOLUME_RATIO => 'light',
            $ratio > self::BIG_VOLUME_RATIO => 'big',
            default => 'usual',
        };
    }


    private function cardFlavor(int $cardId): string
    {
        $card = RunCard::query()->with('activity.detail')->find($cardId);

        return $card === null ? 'a quiet session, filed anyway.' : $this->cardFlavorFor($card);
    }

    /**
     * Card flavor woven from the card's own context (rarity + special move +
     * distance + first badge), seeded by card id so each card reads differently
     * while staying deterministic. Mirrors the per-rarity pools of the real
     * {@see \App\Services\AI\Narrators\CardFlavorNarrator}, minus the LLM.
     */
    public function cardFlavorFor(RunCard $card): string
    {
        $cardId = $card->id;
        $move = $card->special_move;
        $distance = $card->activity->detail?->distance;
        $km = DistanceFormatter::kmString($distance !== null ? (float) $distance : null);

        $pool = self::FLAVOR_POOLS[$card->rarity->value];
        $templates = $km === null
            ? array_values(array_filter($pool, fn (string $t): bool => ! str_contains($t, '{km}')))
            : $pool;
        if ($templates === []) {
            $templates = $pool; // every pool keeps km-less variants; stay non-empty regardless
        }

        // Fold the badge set into the base pick (not just the appended clause) so
        // two commons that differ only by badge don't also land on the identical
        // base sentence. Badgeless cards fold a constant, so they still vary by id.
        $flavorSeed = $cardId + crc32(implode(',', $card->badges ?? []));
        $base = strtr($this->select($templates, $flavorSeed), ['{move}' => $move, '{km}' => (string) $km]);
        $badgeClause = $this->badgeClause($card->badges, $cardId);

        return $badgeClause === null ? $base : ucfirst($base).' '.$badgeClause;
    }

    /**
     * Per-rarity flavor templates. `{move}` and `{km}` are filled from the card;
     * km-less templates exist so a GPS-free run never renders an empty number.
     *
     * @var array<string, non-empty-list<string>>
     */
    private const array FLAVOR_POOLS = [
        'common' => [
            '"{move}" was routine start to finish. Not every card needs to be a story.',
            'A flat {km} km. It goes on the pile with the rest.',
            'No drama in "{move}". The rhythm just held.',
            '"{move}", filed. Ordinary weeks are built out of runs like this.',
            '{km} km, clean and unremarkable. Both of those are true.',
            '"{move}" did exactly what it said it would.',
            'a {km} km run with nothing in it that needs explaining.',
            '"{move}" is the shape most of your weeks are made of.',
            "{km} km that will blend into the rest of the month. That's fine.",
            '"{move}" came and went without much to say about it.',
            'A quiet {km} km. The pile it lands on is the point.',
            '"{move}" is a base run, and the log needs plenty of those.',
            '{km} km of nothing unusual. Most of the log looks like this.',
            '"{move}" holds the line and doesn\'t try for more.',
            'A {km} km run you\'ll have forgotten by next week. It still counts.',
        ],
        'uncommon' => [
            '"{move}" had something in it the routine ones don\'t.',
            'a {km} km run that reads a notch above your usual.',
            'one stretch of "{move}" is the whole reason this card exists.',
            '"{move}" didn\'t go the way your usual sessions go.',
            "{km} km, and not the forgettable kind.",
        ],
        'rare' => [
            '"{move}" doesn\'t turn up often. Worth knowing that.',
            "a {km} km run you don't put together most weeks.",
            'something in "{move}" separates it from the rest of the month.',
            '"{move}" is the rare one, and the log agrees.',
            '{km} km at a level you reach maybe once in a stretch.',
        ],
        'epic' => [
            '"{move}" sat well above your usual gear.',
            'a {km} km run that took real work, and it shows.',
            '"{move}" is the kind of session that shifts what normal means for you.',
            '"{move}" was you at the top of your current range.',
            "{km} km that cost you something. That's why the card looks like this.",
        ],
        'legendary' => [
            '"{move}" is one you\'ll still be referencing months from now.',
            'a {km} km run that goes straight into your own history.',
            '"{move}" is the outlier. Every other card sits under it.',
            '"{move}" reset the ceiling.',
            "{km} km. There's no ordinary version of this one.",
        ],
    ];

    /**
     * Short badge-driven coda, picked deterministically when the card carries a
     * badge. Returns null when there's nothing notable to add.
     *
     * @param  array<int, string>|null  $badges
     */
    private function badgeClause(?array $badges, int $seed): ?string
    {
        if ($badges === null || $badges === []) {
            return null;
        }

        $clauses = [
            Badge::NegativeSplit->value => 'Second half came in faster than the first.',
            Badge::HeatTamer->value => 'Run in the worst heat of the day.',
            Badge::RainWarrior->value => 'Ran it in the rain.',
            Badge::EarlyBird->value => 'Out the door before the city woke up.',
            Badge::LongSlowDistance->value => "Long, slow, and you didn't rush it.",
            Badge::HeldBack->value => 'Pace held back from the first km.',
            Badge::NightOwl->value => 'Logged well after dark.',
            Badge::Climber->value => 'Serious elevation on this one.',
            Badge::FirstTimer->value => 'First of its kind in your log.',
            Badge::Speedster->value => 'Sub-5 per km.',
            Badge::LongHauler->value => 'Half marathon distance and up.',
            Badge::Z2Master->value => 'Mostly Z2, which takes discipline to hold.',
            Badge::ColdRunner->value => "Early enough that the air hadn't warmed up yet.",
            Badge::AllOut->value => 'HR stayed high from start to finish.',
            Badge::EasyMiles->value => 'Genuinely easy. HR never climbed.',
            Badge::Headwind->value => 'Headwind the whole way.',
        ];

        // Highlight one of the card's badges, chosen by seed so multi-badge
        // cards don't all lean on the same coda.
        $known = array_values(array_filter($badges, fn (string $b): bool => isset($clauses[$b])));
        if ($known === []) {
            return null;
        }

        return $clauses[$known[abs($seed) % count($known)]];
    }

    /**
     * @param  non-empty-list<string>  $pool
     */
    private function select(array $pool, int $seed): string
    {
        return $pool[abs($seed) % count($pool)];
    }

    private function profileVoice(int $seed): string
    {
        return $this->select([
            "You lean **chill** far more than pushed, and the log backs it up: regular, unhurried, never a big jump. That's a base built the slow way. The open question is when you decide to spend it.",
            "Your mood spread sits on **easy** and stays there. You keep coming back week after week, which is the part that's hard to fake. The faster end of your range has been quiet for a while though.",
            "You pick routine over flash, and your weekly streak is the proof. Base first, speed later. The foundation for a harder session is already sitting there, unused.",
            "**Blazing** turns up more often now than it did in the quieter months, and your records moved the same direction. You're in a bold phase. Keep one easy run in the mix so the phase lasts.",
            "Your runs split between **blazing** days and wobbly ones, and your longest distance came out of the bold end. You like to push. The easy days are what make the hard ones repeatable.",
            "The pattern reads disciplined: mostly **chill**, pushed on occasion, and your total built out of steady volume rather than one heroic run. Nothing in here is accidental.",
            "You're early and the mix is still thin, but you've come back more than once already. That's a start with a number attached. The reading gets sharper the more you feed it.",
        ], $seed);
    }

    private function trendRead(int $seed): string
    {
        return $this->select([
            "steady is the read.\n\nNothing in this window moved sharply enough to call out on its own. The rhythm held, which is its own kind of answer.",
            "the numbers are still catching up.\n\nThere isn't quite enough history in this window yet for a sharper read. Keep logging and the picture fills in.",
            "a quiet stretch.\n\nNo big swings in either direction this window. Sometimes the story is that there isn't one.",
            "the trend line sat flat.\n\nNeither a climb nor a drop stands out here. Worth checking back once a few more weeks are in.",
        ], $seed);
    }

    private function monthlyRecap(int $userId, ?string $month, int $seed): string
    {
        if ($month === null) {
            return "No dated runs found for this month. There's nothing honest to recap yet.";
        }

        $start = Carbon::createFromFormat('!Y-m', $month)?->startOfMonth();
        if ($start === null) {
            return "No dated runs found for this month. There's nothing honest to recap yet.";
        }

        $details = ActivityDetail::query()
            ->forUser($userId)
            ->whereBetween('start_date_local', [$start, $start->copy()->endOfMonth()])
            ->get(['distance']);

        if ($details->isEmpty()) {
            return "No dated runs found for this month. There's nothing honest to recap yet.";
        }

        $runs = $details->count();
        $runWord = $runs === 1 ? 'run' : 'runs';
        $sessionWord = $runs === 1 ? 'session' : 'sessions';
        $totalDistance = $details->sum('distance');
        assert(is_numeric($totalDistance));
        $longestDistance = $details->max('distance');
        assert($longestDistance === null || is_numeric($longestDistance));
        $totalKm = DistanceFormatter::km((float) $totalDistance);
        $km = DecimalFormatter::decimal($totalKm);
        $longestKm = DistanceFormatter::kmString((float) $longestDistance);
        $usualKm = $this->usualMonthlyKm($userId, $start);
        $closer = $usualKm === null
            ? 'No usual month yet to compare it with. This one sets the line.'
            : match ($this->volumeBand($totalKm, $usualKm)) {
                'light' => "Well below your usual month. That's the number, not a verdict on it.",
                'big' => 'Well above your usual month. The volume is the headline.',
                default => "Right around your usual month. That's the range where the work actually banks.",
            };

        return $this->select([
            "{$km} km across {$runs} {$runWord} this month. The longest run was {$longestKm} km. {$closer}",
            "The month came to {$km} km from {$runs} {$sessionWord}. Longest run: {$longestKm} km. {$closer}",
            "{$runs} {$runWord}, {$km} km in total, and {$longestKm} km was the longest. {$closer}",
            "{$km} km over {$runs} {$sessionWord} this month. The longest was {$longestKm} km. {$closer}",
        ], $seed);
    }

    /** The trailing mean of up to six prior active months' distance. */
    private function usualMonthlyKm(int $userId, Carbon $monthStart): ?float
    {
        $distances = ActivityDetail::query()
            ->selectRaw("DATE_FORMAT(start_date_local, '%Y-%m') as month, SUM(COALESCE(distance, 0)) / 1000 as distance_km")
            ->forUser($userId)
            ->where('start_date_local', '<', $monthStart)
            ->groupBy('month')
            ->orderByDesc('month')
            ->limit(6)
            ->pluck('distance_km');

        return $distances->isEmpty() ? null : (float) $distances->avg();
    }
}
