<?php

declare(strict_types=1);

namespace App\Services\AI\RuleBased;

use App\Enums\Badge;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\Season;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Rule-based content per AnalysisType. Reached in production, not only by the
 * demo seed:
 * - {@see \App\Listeners\DispatchPostRunAnalysis} for material past
 *   `ai.backfill_max_age_days`, and {@see \App\Services\AI\BackfillAgeGate} for
 *   a manual retry on the same
 * - {@see \App\Jobs\AI\AnalyzeRowJob} / {@see \App\Jobs\AI\AnalyzeGroupJob} when
 *   Azure content-filters a generation twice over
 * - the public demo account's triggers, and DemoSeedCommand's backfill
 *
 * Output is deterministic and Temari-voiced. Where the subject's real data is
 * available it drives the copy (run insight via {@see RuleBasedRunInsights}
 * so a seeded demo shows the run's real numbers), falling back to seeded
 * variants only when the subject row is missing. A "Reread" gets real LLM
 * output whenever the subject is inside the narration age cutoff.
 */
final readonly class RuleBasedNarrationFiller
{
    public function fillFor(Analysis $row): string
    {
        $seed = $this->seedFor($row);

        return match ($row->analysis_type) {
            AnalysisType::BriefingMascotVoice => $this->briefingMascotVoice($seed),
            AnalysisType::PostRunSpeech => $this->postRunSpeech($seed),
            AnalysisType::RunInsight => $this->runInsight($seed),
            AnalysisType::WeeklyRecap => $this->weeklyRecap($seed),
            AnalysisType::PrContext => $this->prContext($seed),
            AnalysisType::CardFlavor => $this->cardFlavor($seed),
            AnalysisType::ProfileVoice => $this->profileVoice($seed),
            AnalysisType::MonthlyRecap => $this->monthlyRecap($seed),
            AnalysisType::TrendRead => $this->trendRead($seed),
            AnalysisType::PlanDayVoice => $this->planDayVoice($row),
            AnalysisType::PlanWeekVoice => $this->planWeekVoice($row),
            AnalysisType::PlanSeasonVoice => $this->planSeasonVoice($row),
        };
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

        return $row->subject_id + (int) crc32($row->discriminator);
    }


    private function briefingMascotVoice(int $seed): string
    {
        return $this->select([
            "Easy tempo, 35-45 minutes.\n\nnothing quality has gone into the log since last week and your rhythm's been flat and steady the whole time, so today's the day to break that up. 10 minutes easy to warm up, 15-20 minutes a bit quicker than your usual pace, then cool down. cadence 175+.\n\nWhat to watch: if HR climbs fast at easy pace, drop it to a 15-25 minute run-walk and stop at the cooldown. Brutal heat is reason enough to run the whole thing easy instead.",
            "Easy run, 30-40 minutes.\n\nyour last two sessions both read heavy and the gap since then is short, so today is easy, and I mean actually easy. hold your normal pace, breathing loose enough to talk, cadence 170+ so the steps stay light.\n\nWhat to watch: legs still heavy or HR up early means the recovery isn't finished. A brisk 20-minute walk covers the day.",
            "Long run, 8-12 km easy.\n\nYour weekly distance has crept up every week this month, and this is the session that closes it out. conversational pace the whole way, don't go chasing a time, take water if it's hot.\n\nWhat to watch: if km 5 already feels like work, cut it at 6-8. short and clean beats long and ugly.",
            "Rest today.\n\nyou've run every day of this stretch and the load is stacked higher than anything you've been carrying. no run from me today. light mobility, or a 20-minute walk if you want the legs moving.\n\nWhat to watch: still heavy tomorrow, take another one. A zero today is what keeps the rest of the week.",
            "Easy run, 25-30 minutes.\n\nfirst one back after a few days off, so this one is short on purpose. slow, effort by feel, don't look at the pace at all.\n\nWhat to watch: the classic move after time off is picking up exactly where you left off. If the breathing gets heavy before minute 15, ease off or walk a bit.",
            "Base run, 5-7 km.\n\nYou've hit every session you meant to this week and nothing in the numbers says back off, so today just holds the rhythm that's already working. your average pace, one steady block, no gear changes.\n\nWhat to watch: a run like this turns into a tempo halfway through if you let it. Save the push for a day it's actually on the plan.",
        ], $seed);
    }

    private function detailFor(int $activityId): ?ActivityDetail
    {
        return ActivityDetail::query()->where('activity_id', $activityId)->first();
    }

    private function postRunSpeech(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        if ($detail === null) {
            return "Done. That one's in the log.";
        }
        $km = DistanceFormatter::kmString($detail->distance) ?? '?';

        // Hashed (not the bare, sequential activityId), or a run of consecutive
        // activities — exactly what the History feed shows side by side — walks
        // the pool in lockstep and repeats the identical line every N-th run.
        $baseSeed = (int) crc32('post_run_speech_'.$activityId);

        $base = $this->select([
            "{$km} km, logged. Pace held all the way to the end.",
            "{$km} km. The rhythm never wobbled once.",
            "Finished {$km} km. Breathing stayed under control the whole way through.",
            "{$km} km in the log. Nothing dramatic, nothing sloppy.",
            "{$km} km today. Not fast. Still on the board.",
            "Another {$km} km on the pile.",
            "{$km} km, and the effort read about right. Not forced, not coasting either.",
            "{$km} km down. The week's total just moved.",
            "{$km} km. Showed up, ran it, went home.",
            "{$km} km, clean. Nothing in here to fix.",
            "Today's line in the log: {$km} km.",
            "{$km} km. How did the legs feel by the end of that one?",
        ], $baseSeed);

        return $base . $this->postRunCoda($detail, $activityId);
    }

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
        } elseif ($detail->weather_temp_c !== null && $detail->weather_temp_c >= 31) {
            $codas[] = " That was at {$detail->weather_temp_c} degrees, so it cost more than the pace lets on.";
        }

        return $codas === [] ? '' : $codas[abs($seed) % count($codas)];
    }


    /**
     * The run-insight block's content is a JSON-encoded claims list, matching
     * exactly what {@see \App\Services\AI\Narrators\RunInsightNarrator} persists
     * so the frontend reads one shape regardless of which path filled it. An
     * activity with no readable detail yet renders no claims, same as a real
     * run with nothing falsifiable to say.
     */
    private function runInsight(int $activityId): string
    {
        $detail = $this->detailFor($activityId);
        $claims = $detail === null ? [] : RuleBasedRunInsights::claims($detail);

        return (string) json_encode($claims, JSON_THROW_ON_ERROR);
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
        $closer = match ($snapshot->form_status) {
            'fresh' => "You're fresh, with room to add a little on top of that.",
            'optimal' => "That's the range where the work actually banks.",
            'fatigued' => 'The fatigue is showing. Bank some recovery next week.',
            'overreaching' => "Your load is above what you've been carrying lately. Worth pulling something back.",
            default => "Steady. That's the read.",
        };

        return $this->select([
            "{$km} km across {$runs} runs this week. {$closer}",
            "{$runs} sessions, {$km} km on the board. {$closer}",
            "{$km} km this week, spread over {$runs} runs. {$closer}",
            "{$runs} runs, {$km} km. {$closer}",
            "The week came to {$km} km from {$runs} sessions. {$closer}",
            "{$km} km logged, {$runs} times out the door. {$closer}",
        ], $snapshotId);
    }

    private function prContext(int $seed): string
    {
        return $this->select([
            "That's a new PR. The old number held until today, and now it doesn't.",
            'New best at that distance. Nothing about it was luck.',
            "You beat your own number. That's the only record that counts here.",
            'PR. The seconds you took off were bought weeks ago, in sessions that felt like nothing at the time.',
            "That's the new mark. Everything from here gets measured against it.",
            'New PR on the board. The old one had been sitting there a while.',
            "The gap looks small written down. It wasn't small to run.",
        ], $seed);
    }


    /**
     * Card flavor woven from the card's own context (rarity + special move +
     * distance + first badge), seeded by card id so each card reads differently
     * while staying deterministic. Mirrors the per-rarity pools of the real
     * {@see \App\Services\AI\Narrators\CardFlavorNarrator}, minus the LLM.
     */
    private function cardFlavor(int $cardId): string
    {
        $card = RunCard::query()->with('activity.detail')->find($cardId);
        if ($card === null) {
            return 'A quiet session, filed anyway.';
        }

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
        $flavorSeed = $cardId + (int) crc32(implode(',', $card->badges ?? []));
        $base = strtr($this->select($templates, $flavorSeed), ['{move}' => $move, '{km}' => (string) $km]);
        $badgeClause = $this->badgeClause($card->badges, $cardId);

        return $badgeClause === null ? $base : $base . ' ' . $badgeClause;
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
        ],
        'uncommon' => [
            '"{move}" had something in it the routine ones don\'t.',
            'A {km} km run that reads a notch above your usual.',
            'One stretch of "{move}" is the whole reason this card exists.',
            '"{move}" didn\'t go the way your usual sessions go.',
            "{km} km, and not the forgettable kind.",
        ],
        'rare' => [
            '"{move}" doesn\'t turn up often. Worth knowing that.',
            "A {km} km run you don't put together most weeks.",
            'Something in "{move}" separates it from the rest of the month.',
            '"{move}" is the rare one, and the log agrees.',
            '{km} km at a level you reach maybe once in a stretch.',
        ],
        'epic' => [
            '"{move}" sat well above your usual gear.',
            'A {km} km run that took real work, and it shows.',
            '"{move}" is the kind of session that shifts what normal means for you.',
            '"{move}" was you at the top of your current range.',
            "{km} km that cost you something. That's why the card looks like this.",
        ],
        'legendary' => [
            '"{move}" is one you\'ll still be referencing months from now.',
            'A {km} km run that goes straight into your own history.',
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
            "**blazing** turns up more often now than it did in the quieter months, and your records moved the same direction. You're in a bold phase. Keep one easy run in the mix so the phase lasts.",
            "Your runs split between **blazing** days and wobbly ones, and your longest distance came out of the bold end. You like to push. The easy days are what make the hard ones repeatable.",
            "The pattern reads disciplined: mostly **chill**, pushed on occasion, and your total built out of steady volume rather than one heroic run. Nothing in here is accidental.",
            "You're early and the mix is still thin, but you've come back more than once already. That's a start with a number attached. The reading gets sharper the more you feed it.",
        ], $seed);
    }

    /**
     * Matches TrendReadNarrator's own title-then-description shape (joined by
     * a blank line) so the frontend never has to special-case which pipeline
     * produced a given block before splitting it.
     */
    private function planDayVoice(Analysis $row): string
    {
        $session = PlannedSession::query()
            ->where('user_id', $row->subject_id)
            ->where('date', $row->discriminator)
            ->first();

        if ($session === null) {
            return "today's plan.";
        }

        if ($session->skipped) {
            return $this->select([
                'skipped. next one is still on the schedule.',
                'excused for today. picks back up next session.',
            ], $this->seedFor($row));
        }

        return match ($session->session_type) {
            SessionType::Rest => $this->select(['rest. 🛌', 'a day off. nothing to log.'], $this->seedFor($row)),
            SessionType::Long => $this->select([
                "long run today. this is the one the week's built around.",
                'the long one. settle in.',
            ], $this->seedFor($row)),
            SessionType::Tempo => $this->select(['tempo work today.', 'a tempo day on the calendar.'], $this->seedFor($row)),
            SessionType::Interval => $this->select(['interval work today.', 'reps on the schedule.'], $this->seedFor($row)),
            SessionType::Easy => $this->select(['easy day. nothing to prove, just log the miles.', 'an easy one today.'], $this->seedFor($row)),
        };
    }

    private function planWeekVoice(Analysis $row): string
    {
        $adaptation = PlanAdaptation::query()->find($row->subject_id);
        if ($adaptation === null) {
            return 'a fresh week on the plan.';
        }

        if ($adaptation->deload) {
            return $this->select([
                'lighter week. the last stretch ran hot, this one lets it cool.',
                'a deload week. the legs get a break before the next push.',
            ], $this->seedFor($row));
        }

        return $this->select([
            'steady week ahead, same shape as last.',
            'business as usual this week.',
        ], $this->seedFor($row));
    }

    private function planSeasonVoice(Analysis $row): string
    {
        $season = Season::query()->find($row->subject_id);
        if ($season === null) {
            return 'a new training arc, just getting started.';
        }

        $race = $season->raceGoal;
        if ($race !== null) {
            $raceName = $race->name ?? 'the race';

            return $this->select([
                "building toward {$raceName}.",
                "the arc that gets you to {$raceName}.",
            ], $this->seedFor($row));
        }

        return $this->select([
            "no race on the books right now, so this one's about building a base.",
            'a self-scaled block, building fitness with no countdown attached.',
        ], $this->seedFor($row));
    }

    private function trendRead(int $seed): string
    {
        return $this->select([
            "Steady is the read.\n\nNothing in this window moved sharply enough to call out on its own. The rhythm held, which is its own kind of answer.",
            "The numbers are still catching up.\n\nThere isn't quite enough history in this window yet for a sharper read. Keep logging and the picture fills in.",
            "A quiet stretch.\n\nNo big swings in either direction this window. Sometimes the story is that there isn't one.",
            "The trend line sat flat.\n\nNeither a climb nor a drop stands out here. Worth checking back once a few more weeks are in.",
        ], $seed);
    }

    private function monthlyRecap(int $seed): string
    {
        return $this->select([
            "The rhythm held all month. You didn't force it and you didn't disappear either.",
            'A full month of regular running. The volume made sense and the effort stayed in hand.',
            'No real gaps this month. Showed up, ran, went home, repeatedly.',
            "This month traded intensity for consistency. That's a trade, not a free win.",
            "You kept showing up this month without being fast about it. The total says what the paces didn't.",
            'A clean month. Volume on track, effort never forced, nothing to untangle.',
            'This month leaned patient. That works right up until patient turns into a habit.',
        ], $seed);
    }
}
