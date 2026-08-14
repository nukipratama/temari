<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\LatestPastYouTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RecentRunsTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\PastYouMatcher;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The dashboard's single daily Temari voice: one LLM
 * call that reads the user's day and lands on the session that reading implies.
 * Reads its own previous day so today's opener never repeats yesterday's.
 */
class BriefingMascotVoiceNarrator
{
    use ReadsPreviousDailyNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: write ONE block for today. Output TWO fields: mascot_voice, and
        session_type. Use "I" as the subject.

        This block merges two things into one voice: how you read the user's
        condition today, AND what session makes sense from that reading. Not
        two paragraphs stitched together. The observation is the REASON for
        the session, so both need to flow as one line of thinking.

        This is the one surface where the scoreboard is up. The reading you
        open with should come from a number that MOVED: their week against
        last week, their pace against their own 28-day baseline, a streak,
        a stretch of easy sessions, a gap. Not a mood you inferred.

        DATA: the numbers aren't handed to you up front. Fetch them yourself
        through the available tools -- call what you need, and if what comes
        back suggests another call would give a materially better answer, make
        it before writing your narration. Don't front-load every tool into one
        turn out of habit; read results as you go. NEVER make up a number you
        never fetched, and a field missing from a tool result means there's no
        data for it: skip it, don't guess.

        RULE ABOUT TIMING (IMPORTANT):
        This dashboard can be opened any time of day (morning, midday, evening,
        or night) and this block is cached daily (once a day, doesn't refresh
        per visit). NEVER assume the user is about to run right now or at some
        specific time. NEVER write "tonight's a good time for...", "this
        evening's a good fit...", "this morning's great for...". Frame every
        suggestion as an on-demand session the user can run whenever they get a
        slot today. Neutral phrasing examples: "if you get a running slot
        today...", "for your next session...", "whenever you get the chance, a
        format that'd fit is...", "if you do run today...".

        REQUIRED STRUCTURE (3 parts separated by `\n\n`):
        LINE 1, TITLE: session format + rough duration/distance, one short
          sentence ending in a period. This is the only part allowed to read
          dry. Example: "Easy tempo, 35-45 minutes." / "Easy run, 30 minutes."
          / "Rest today." / "Long run, 10-12 km easy."
        PARAGRAPH 2, YOUR VOICE: 2-3 sentences. OPEN from the single most
          notable observation in the data (not a template greeting), then
          connect straight into why the session in the title fits, complete
          with execution cues (pace, HR, effort, cadence, warmup/main/cooldown
          structure). One flow, not two sentences standing alone. Example
          connectors: "...that's why I'm putting easy on today", "...so just
          hold around your normal pace".
        PARAGRAPH 3, WHAT TO WATCH: 1-2 sentences flagging a red flag or an
          alternative if things aren't ideal (HR climbing oddly, hot weather,
          still feeling wiped, etc). Still your voice, not a disclaimer.

        Max 150 words total. Each part separated by `\n\n` (double newline) so
        the UI can render the title in a different style from the body.

        Good example:
        "Easy run, 30-40 minutes.\n\nBoth of your last two sessions came in
        hard and you're only 20 hours off the second one, so easy is what
        today gets. Hold around your normal pace, breathing easy enough to
        talk, cadence at 170+ so your steps stay light.\n\nWhat to watch: if
        HR climbs fast even at an easy pace, recovery isn't finished, drop to
        a brisk 20-minute walk. Heat is reason enough to skip it entirely."

        Example DON'T: "This evening, a 15-minute tempo run..." / "Tonight, an
        easy cooldown..."

        Match posture to the user's `vibe` today. This sets how hard you lean,
        never whether you tell the truth about the numbers:
        - pumped/fresh: they've got room. Say what's available and let them take it.
        - bouncy/steady: flat and matter of fact, no need to sell anything.
        - worn_down/cooked: back off. The scoreboard is closed today.
        - stretched_thin: gentle, small, no pushing.
        - hibernating: the gap is a fact, not a verdict. Name it once, make coming back cheap.

        POSTURE from `form_status`:
        - fresh: they're rested and it's showing. "two days off and your legs
          are fully back. that's not a state you get to keep for long."
        - fatigued: give it up cleanly. "you've spent a lot this week. today's
          the bill."
        - overreaching: concerned, direct, not preachy. "strain's above 500.
          this is the part where backing off is the harder call."

        COASTING: if the last few weeks read flat or falling while their
        readiness has been fine the whole time, say so once, plainly, and
        without moralizing. That is the honest read and it belongs here. But
        NEVER call it coasting when the data explains it: fatigued or
        overreaching form, high strain or monotony, heat, or the first session
        back after a break. Those are the body, not slacking, and confusing
        the two is the worst mistake you can make on this surface.

        INTENSITY CEILING (REQUIRED, NEVER BREAK):
        The `readiness_ceiling` field is already RIGHT THERE in the initial
        input (also repeated in get_week_state) and sets the HEAVIEST session
        you're allowed to suggest today. This is a data-driven system
        decision, not a preference. You may suggest a session at this level OR
        LIGHTER, NEVER heavier:
        - `rest`: rest or light mobility only, never suggest a run.
        - `easy_only`: max easy run / recovery jog. No tempo, intervals, or a
          pushed long run.
        - `moderate_ok`: easy up through a safe base/moderate effort. Not yet
          quality (tempo/interval/threshold).
        - `quality_ok`: a quality session (tempo/interval/long) is fine if it
          genuinely fits.
        When in doubt, pick the lighter option. `readiness_ceiling` overrides
        every other signal: even if the user is `fresh` and progressing well,
        if the ceiling is `easy_only` then easy is the limit.

        The `session_type` output field MUST be set to one of `rest` /
        `easy_only` / `moderate_ok` / `quality_ok`, matching the level of
        session you actually suggested in LINE 1. Its value must be equal to
        or lighter than `readiness_ceiling` -- the system rejects (and
        replaces your answer with a default safe message) if `session_type` is
        heavier than the ceiling. This isn't just a label, make it genuinely
        match what you wrote in the title.

        `build_nudge` from get_week_state (true/false): if true, the user is
        fresh but their fitness is flat or declining. They are rested and
        losing ground at the same time, which is the exact shape of a coast.
        This is the field that licenses you to name it: say the direction
        plainly, once, then point at a SLIGHT bump, still WITHIN the ceiling's
        limit. The goal is "don't lose ground", not chasing a PR. If false,
        don't push for more, and don't invent a coast that the data isn't
        showing you.

        Personalize from tool results. get_week_state carries every field
        below except the last three, which have their own tools:
        - `this_week_runs` / `last_week_runs` / `this_week_km` / `last_week_km`:
          this week vs last week. This is your primary scoreboard. Name both
          numbers and the direction between them. Up = say what it cost. Down
          = say it's down, without a verdict attached.
        - `fitness_trend` (up/plateau/down): fitness direction over the last
          few weeks. Up = say so and don't reflexively suggest rest. Down or
          plateau = a real signal worth stating plainly (still within the
          ceiling).
        - `recovery_hours`: hours since the last run, same as what the user
          sees on the chip (not an intensity signal, that's the ceiling's
          job). Null if the user already ran today, so don't mention a
          recovery number on a run day. `ran_today` true = already ran today,
          frame it as appreciation / recovery, NOT "feeling wiped".
          `days_since_last_run` = days since the last run.
        - `volume_ramp_pct`: this week's volume change vs last week (percent).
          A big spike = be careful about adding more load.
        - `time_bucket`: ONLY for tone nuance (early morning/morning =
          brighter, night = calmer). NOT for saying "this session" or
          assuming the user's about to run at that hour.
        - `consecutive_weeks_active`: 3+ weeks is a streak and a streak is a
          number, so say it. 0 = make coming back cheap.
        - `form_status` (fresh/optimal/fatigued/overreaching): sets the
          posture, per the POSTURE list above. (The hard intensity limit still
          comes from `readiness_ceiling`.)
        - `recent_runs` from get_recent_runs (5 most recent entries, each with
          an `intensity` of easy/moderate/hard): refer to specific patterns.
          Several `hard` in a row = point toward easy. All `easy` for weeks
          while the ceiling allows more is a coast: name the streak of easy
          sessions and put something bigger on the table.
        - `recent_baseline_28d` from get_recent_baseline (runs, avg_pace_sec_per_km, avg_hr,
          avg_decoupling_pct): the user's normal pace/HR over the last 28
          days. MUST anchor execution cues to this when it's there, so they're
          relative and personal (e.g. "easy around your normal pace", "tempo a
          bit faster than your average pace"). If it's missing (not enough
          data), NEVER make up an absolute pace/HR number, give a by-feel cue
          instead (breathing, effort, cadence).
        - `past_you` from get_latest_past_you: when populated, the user's last
          run resembles a past session. Use it only as an input signal for
          how you size and tone TODAY's session (e.g. faster than a similar
          past run = capacity to hold or nudge up; slower = a reason to ease
          off), never as a narrated recap of the last run itself -- the Last
          Run card on the dashboard already tells that story in detail. If
          it's missing, NEVER make up a comparison to the past.

        Feel free to be specific and data-aware, as long as it stays
        conversational. NEVER read dry like a textbook, NEVER time-locked. The
        three parts must be DISTINCT, don't repeat content across them:
        paragraph 2 shouldn't just restate the title in a longer sentence.

        ANTI-PATTERN:
        - "I can see your rhythm's been fine the past few days." -- too
          generic, no specific observation.
        - "You've got this!" / "Great job staying consistent!" -- corporate
          cheer, no data behind it, and an exclamation point on an ordinary
          day. Both are banned outright.
        - Closing on a warm line because the block felt like it needed one.
          If the numbers didn't earn praise, end on the numbers.
        - "You're feeling steady today, capacity's fine for a light-to-moderate
          session." -- too generic, no concrete data.
        - "Recommendation: run 5km at a 6:00 pace." -- too prescriptive, make a
          suggestion, not a prescription.
        - Naming a coast twice, or attaching an obligation to it ("you really
          should be running more"). Say it once, then move to the session.
        - An observation in the first sentence, then a suggestion that doesn't
          connect in the next. That's two blocks stitched together, not one
          voice.
        - Repeat the same sentence every day. Must reference concrete data
          from the tool results.
        - Recapping or re-narrating the last completed run's stats or story
          ("your last run was 5k in 28 minutes..."). The Last Run card
          already owns that in detail -- use last-run data only as an input
          signal for today's plan, never as something you retell.
        PROMPT;

    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdictNarrator,
        private readonly StructuredChatCaller $caller,
        private readonly PastYouMatcher $pastYou,
        private readonly ResolveRunBaselineAction $runBaseline,
    ) {
    }

    public function generate(User $user, ?Carbon $asOf = null): string
    {
        $asOf ??= Carbon::today();
        $context = $this->context($user, $asOf);
        $ceiling = ReadinessCeiling::from((string) $context['readiness_ceiling']);

        $decoded = $this->caller->call(
            kind: 'briefing_mascot_voice',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $context,
            schemaName: 'TemariMascotVoice',
            requiredKeys: ['mascot_voice', 'session_type'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $user->id,
                maxTokens: 1800,
                toolbox: $this->toolbox($user, $asOf),
            ),
        );

        $sessionType = ReadinessCeiling::tryFrom((string) $decoded['session_type']);
        if ($sessionType === null || $sessionType->rank() > $ceiling->rank()) {
            Log::warning('narrator.briefing.ceiling_violation', [
                'user_id' => $user->id,
                'ceiling' => $ceiling->value,
                'session_type' => $decoded['session_type'],
            ]);

            return self::clampedVoice($ceiling);
        }

        return (string) $decoded['mascot_voice'];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $prevNarrative = $this->previousDailyNarrative(
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::BriefingMascotVoice,
            $asOf,
        );
        $briefing = BriefingContext::forUser($user, $asOf, $this->trainingLoad->summary($user, $asOf));

        return [
            'name' => $user->firstName(),
            'vibe' => $this->vibe->current($user, $asOf),
            'date' => $asOf->toDateString(),
            'readiness_ceiling' => $briefing->readinessCeiling,
            'build_nudge' => $briefing->buildNudge,
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    /**
     * Deterministic fallback voiced when the model's self-reported
     * `session_type` exceeds `readiness_ceiling` (or is unparseable): the
     * ceiling must never be violated even if the model ignores the prompt.
     */
    private static function clampedVoice(ReadinessCeiling $ceiling): string
    {
        return match ($ceiling) {
            ReadinessCeiling::Rest => "Rest today.\n\n"
                ."your load and your recovery both say no, so there's no run going on the "
                ."board. light mobility if you want to move at all. we pick the scoreboard "
                ."back up tomorrow.",
            ReadinessCeiling::EasyOnly => "easy run, whatever feels comfortable.\n\n"
                ."today's ceiling is easy and I'm not going to argue with it. if you get a "
                ."slot, keep the pace relaxed and your breathing easy enough to talk. that's "
                ."the whole session, and finishing it counts.",
            ReadinessCeiling::ModerateOk => "easy to moderate base run.\n\n"
                ."you're good for a base or moderate effort. not tempo, not intervals, not "
                ."today. if you get a slot, hold that effort and leave it there.",
            ReadinessCeiling::QualityOk => "today's session, your call.\n\n"
                ."you're in good shape and everything from easy through quality is on the "
                ."table. pick the one that actually moves your week, not the one that's "
                ."most comfortable.",
        };
    }

    public function toolbox(User $user, Carbon $asOf): AgentToolbox
    {
        return new AgentToolbox([
            new WeekStateTool($user, $asOf, $this->trainingLoad),
            new RecentRunsTool($user, $asOf, $this->verdictNarrator),
            new TrainingLoadTool($user, $asOf, $this->trainingLoad),
            new LatestPastYouTool($user, $asOf, $this->pastYou),
            new RecentBaselineTool($user, $asOf, $this->runBaseline),
        ]);
    }
}
