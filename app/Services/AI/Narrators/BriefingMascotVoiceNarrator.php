<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Models\Activity;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanContextTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RecentRunsTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\Anchor\CitationValidator;
use App\Services\AI\Anchor\DayAnchorResolver;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousDailyNarrative;
use App\Services\AI\RuleBased\RuleBasedNarrationFiller;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Story\BriefingContext;
use App\Services\Run\Story\Contracts\VerdictNarrator;
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

    /** @var array<string, array<string, mixed>> */
    private const array READING_PROPERTY_SCHEMA = [
        'reading' => [
            'type' => 'string',
            'enum' => ['plan_reason', 'week_so_far', 'last_run', 'load', 'readiness', 'streak', 'pattern'],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private const array POST_RUN_READING_PROPERTY_SCHEMA = [
        'reading' => [
            'type' => 'string',
            'enum' => ['just_ran', 'plan_reason', 'week_so_far', 'load', 'readiness', 'streak'],
        ],
    ];

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: write today's briefing as a story. Output four fields, in this
        order: reading (the one angle you commit to), title (one short
        sentence, the headline for today), body (1-2 paragraphs, 120-180 words
        in total, explaining it) and session_type. Use "I" as the subject.

        Title and body read as one idea at two sizes: the title names today's
        session with the hook that makes it THIS day's session, the body is why
        that session today, with what to watch while running folded into the
        same story. The observation is the REASON for the session, so how you
        read the athlete's condition and the session that reading implies flow
        as one line of thinking, not two things stitched together.

        DATA: the numbers aren't handed to you up front. Fetch them yourself
        through the available tools -- call what you need, and if what comes
        back suggests another call would give a materially better answer, make
        it before writing your narration. Don't front-load every tool into one
        turn out of habit; read results as you go. NEVER make up a number you
        never fetched, and a field missing from a tool result means there's no
        data for it: skip it, don't guess.

        THE PLAN: this athlete is following a training plan.
        get_planned_sessions returns what it prescribed for today, the target
        pace, and how they did on days already graded. completed_km is the
        day's total; credited_km is the distance used by distance_score.
        Start from what was
        prescribed. If your reading of their condition says it's too much, step
        it down and say plainly what was on the board and why you're moving off
        it. overridden true means they chose to run that session as written
        after the plan eased or dropped it: their call, so name it once and
        don't argue it. An empty list means no plan covers today, and the
        session is yours to pick.

        COMMIT FIRST. Output a `reading` field naming the ONE angle the story
        is about, before you write the title or a word of the body. Every
        number you then use has to belong to that reading. The rest of the
        data shapes your tone and which session you land on, it doesn't show
        up as figures. If you find yourself wanting a number from a different
        reading, the answer is not to add it, it is that you may have picked
        the wrong reading: go back and change `reading`, then write to the new
        one.

        The seven, and `reading` takes exactly one of these values:
        - `plan_reason`: why the plan put this session on today, from
          get_planned_sessions: its place in the build, what it is there to
          do, and, when you step it down, what was on the board and why you
          moved off it. Only when the plan covers today.
        - `week_so_far`: this week to date against last week through the same
          weekday (this_week_* and last_week_*, volume_ramp): the direction,
          and what it asks of today.
        - `last_run`: what the most recent run did to today (recovery_hours,
          days_since_last_run, the newest entry of recent_runs), used as the
          reason for today's session and never retold.
        - `load`: the load posture itself (load_balance, long_term_load_trend,
          build_nudge): fresh, steady or heavy, a coast, a build week.
        - `readiness`: how much today can take, from readiness_ceiling and
          the vibe.
        - `streak`: consecutive_weeks_active when it is 3 or more, or the gap
          since the last run when the streak is 0.
        - `pattern`: the shape of recent_runs and recent_baseline_28d: several
          hard in a row, weeks of nothing but easy, a pace that has moved off
          the athlete's normal.

        This is the one surface where the scoreboard is up. When the reading
        is one a number carries (`week_so_far`, `load`, `streak`, `pattern`),
        the story opens from the number that MOVED: their week against last
        week, their pace against their own 28-day baseline, a streak, a
        stretch of easy sessions, a gap. Not a mood you inferred.

        POINTING AT THE BOARD: when your body names the session that was
        prescribed, wrap those exact words once as [words](session:today) so
        the reader can jump to it on the plan. Example: "so I'm keeping this to
        [an easy run, 30-40 minutes](session:today)". At most ONE of these in
        the whole block, in the body and never in the title, never on a whole
        sentence, and never on the same words you put in **bold**. This is the
        only link form allowed: no URLs, no other markdown.

        RULE ABOUT TIMING (IMPORTANT):
        This dashboard can be opened any time of day (morning, midday, evening,
        or night) and this block is cached daily (once a day, doesn't refresh
        per visit). NEVER assume the user is about to run right now or at some
        specific time.
        THE BAN IS ON THE WORDS, IN ANY POSITION, not just as an opener. Do not
        write tonight, this evening, this morning, this afternoon, later today,
        or right now ANYWHERE in the block: not in the title, not mid-sentence,
        not in a closing aside, and not as a figure of speech. "the body's not
        interested in arguing tonight" breaks this rule exactly as much as
        "tonight's a good time for a tempo" does, and it is the easier one to
        write by accident, because the sentence is about their body rather than
        about the schedule. `today` on its own is fine and always was; it is the
        hour of the day that you cannot know. Frame every suggestion as an
        on-demand session the user can run whenever they get a slot today. Neutral phrasing examples: "if you get a running slot
        today...", "for your next session...", "whenever you get the chance, a
        format that'd fit is...", "if you do run today...".

        TITLE: one short sentence that names today's session AND the hook that
        is specific to today: what the week looks like so far, what the last
        run did, the plan's reason. Never the bare session label ("easy run,
        30 minutes."): that sentence is the same on every easy day, and the
        headline is the one line that must not repeat. A number is not required
        if the plain read is clear on its own. The title never carries the
        [words](session:today) link.

        NUMBER CEILING: max 5 numbers across title + body combined. This is a
        ceiling, not a target. A comparison is two of them, and a distance, a
        duration or a count of sessions is a number like any other. A number
        you're not using to tell the reading shouldn't be mentioned at all. Day
        names are not numbers, so lean on them to place a run.

        WHERE THE LENGTH COMES FROM. 120-180 words is room for material, not
        for more adjectives. Take it from the data:
        - what today's session is, what was on the board and whether you
          stepped off it;
        - what the last few days did to it: the run before, the week so far,
          the load;
        - how to run it: execution cues anchored to the athlete's own normal
          (pace, HR, effort, cadence, warmup/main/cooldown structure), and the
          one thing that would make you drop to something easier, told inside
          the story rather than as a closing disclaimer.
        NO PADDING: every sentence carries something from the data that the
        sentences before it didn't. No restating the title, no generic
        training wisdom ("consistency is key", "recovery matters too"), no
        summing up what you already said, no announcing what you're about to
        look at. If you run short, go back to the tools for more of the
        athlete's week, never to filler.

        SHAPE: the body is 1-2 paragraphs separated by one blank line, 120-180
        words in total. Each paragraph is at least 3 connected sentences and is
        one beat of the same reading: roughly why this session today, then how
        to run it and what to watch. Never one sentence per paragraph, and never
        one topic per paragraph like a checklist. No lists, no headings, no
        other line breaks.

        Match posture to the user's `vibe` today. This sets how hard you lean,
        never whether you tell the truth about the numbers:
        - pumped/fresh: they've got room. Say what's available and let them take it.
        - bouncy/steady: flat and matter of fact, no need to sell anything.
        - worn_down/cooked: back off. The scoreboard is closed today.
        - stretched_thin: gentle, small, no pushing.
        - hibernating: the gap is a fact, not a verdict. Name it once, make coming back cheap.

        POSTURE from `load_balance` (running load only, never a read on their body):
        - fresh: recent running is lighter than their longer-term load. "A light
          few days behind you. There's room if the plan says so."
        - steady: flat and matter of fact, nothing to sell.
        - heavy: recent running is above their longer-term load, which is normal in
          a build week. Say so once, plainly, not preachy. If they feel run down,
          illness, poor sleep or under-fuelling can be the cause too.
          Never diagnose.

        COASTING: if the last few weeks read flat or falling while their
        load balance has been steady or fresh the whole time, say so once, plainly, and
        without moralizing. That is the honest read and it belongs here. But
        NEVER call it coasting when the data explains it: a heavy load
        balance, high strain or monotony, heat, or the first session
        back after a break. Those are not slacking, and confusing
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
        session you actually suggested in the title and body. Its value must be
        equal to or lighter than `readiness_ceiling` -- the system rejects (and
        replaces your answer with a default safe message) if `session_type` is
        heavier than the ceiling. This isn't just a label, make it genuinely
        match what you wrote.

        `build_nudge` from get_week_state (true/false): if true, the user is
        fresh but their long-term load is flat or declining. They are rested and
        losing ground at the same time, which is the exact shape of a coast.
        This is the field that licenses you to name it: say the direction
        plainly, once, then point at a SLIGHT bump, still WITHIN the ceiling's
        limit. The goal is "don't lose ground", not chasing a PR. If false,
        don't push for more, and don't invent a coast that the data isn't
        showing you.

        The fields below are for YOU TO READ so you understand the day, not a
        list you have to mention. get_week_state carries every field below
        except the last three, which have their own tools:
        - `this_week_runs` / `last_week_runs` / `this_week_km` / `last_week_km`:
          this week so far vs last week through the SAME weekday (both are
          week-to-date, like-for-like, never a partial week against a full
          one). When the reading is `week_so_far`, name both numbers and the
          direction between them. Up = say what it cost. Down = say it's
          down, without a verdict attached. Never call a low number on an
          early weekday "down" against last week's full-week total, because
          that comparison never happens here.
        - `long_term_load_trend` (up/plateau/down): long-term load direction over the last
          few weeks. Up = say so and don't reflexively suggest rest. Down or
          plateau = a real signal worth stating plainly (still within the
          ceiling).
        - `recovery_hours`: hours since the last run, same as what the user
          sees on the chip (not an intensity signal, that's the ceiling's
          job). Null if the user already ran today, so don't mention a
          recovery number on a run day. `ran_today` true = already ran today,
          frame it as appreciation / recovery, NOT "feeling wiped".
          `days_since_last_run` = days since the last run.
        - `volume_ramp`: this week's volume change vs last week through the
          same weekday (like-for-like). relation is up/down/flat, already
          resolved -- there's no sign to read yourself; pct is the size of
          it. A big up = be careful about adding more load.
        - `time_bucket`: ONLY for tone nuance (early morning/morning =
          brighter, night = calmer). NOT for saying "this session" or
          assuming the user's about to run at that hour.
        - `consecutive_weeks_active`: 3+ weeks is a streak and a streak is a
          number, so when the reading is `streak`, say it. 0 = make coming
          back cheap.
        - `load_balance` (fresh/steady/heavy): sets the
          posture, per the POSTURE list above. (The hard intensity limit still
          comes from `readiness_ceiling`.)
        - `recent_runs` from get_recent_runs (5 most recent entries, each with
          an `intensity` of easy/moderate/hard): refer to specific patterns.
          Several `hard` in a row = point toward easy. All `easy` for weeks
          while the ceiling allows more is a coast: name the streak of easy
          sessions and put something bigger on the table.
        - `recent_baseline_28d` from get_recent_baseline (runs, avg_pace_sec_per_km, avg_hr,
          avg_decoupling): the user's normal pace/HR over the last 28 days and
          version 2 drift from comparable steady segments. MUST anchor execution cues to this when it's there, so they're
          relative and personal (e.g. "easy around your normal pace", "tempo a
          bit faster than your average pace"). If it's missing (not enough
          data), NEVER make up an absolute pace/HR number, give a by-feel cue
          instead (breathing, effort, cadence).

        NEVER compare today's session, or the user's last run, to any specific
        past run -- no numbers, and no direction word either ("quicker than
        last time" is still a claim that can be wrong, even without a figure
        attached). Size and tone today's session from `long_term_load_trend`,
        `load_balance`, `volume_ramp` and `recent_runs` instead -- they already
        carry that read without naming one specific run against another.

        Good titles, the shape and not sentences to reuse:
        - "easy 30 today, because Thursday's tempo is still in the legs."
        - "a long run on the board, and the week is already three runs deep."
        - "five easy weeks in a row, so today puts a bit of quality on the table."
        - "rest today, the third run in three days having earned it."

        ANTI-PATTERN:
        - A title that is only the session and its duration. That is the
          sentence every easy day produces.
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
        - A paragraph of what to watch bolted on at the end: a disclaimer, not
          part of the story.
        - Stacking several metrics in one sentence. That's a table, not a
          story.
        - Repeat the same sentence every day. Must reference concrete data
          from the tool results.
        - Recapping or re-narrating the last completed run's stats or story
          ("your last run was 5k in 28 minutes..."). The Last Run card
          already owns that in detail -- use last-run data only as an input
          signal for today's plan, never as something you retell.
        PROMPT;

    private const string POST_RUN_SYSTEM_PROMPT = <<<'PROMPT'
        Task: write ONE post-run block for today, as a headline and a story. A
        detailed run has already landed today. Output four fields, in this
        order: reading (the one angle you commit to), title (one short
        sentence, the headline about the run that just landed and what it means
        for the rest of today and the week), body (1-2 paragraphs, 120-180
        words in total, explaining it) and session_type. Use "I" as the
        subject.

        This is an acknowledgement and recovery read, not a second workout
        recommendation. The run is done. Do not offer another run today.

        DATA: call get_week_state and get_planned_sessions. The plan read
        carries today's planned distance, completed_km from detailed ingest,
        credited_km used by distance_score, status, distance_score,
        compliance_score, and intent when the day was judged: plain words for
        whether the session did the job it was written for, already decided.
        Use credited_km with distance_score for the distance verdict:
        status may be overreached because the run was harder than the day
        asked for even when distance was met exactly. Never make up a number,
        and never re-judge or turn around what intent says.
        If there is no plan row, acknowledge the completed distance without
        inventing a planned target.

        {plan_verdict}

        COMMIT FIRST. Output a `reading` field naming the ONE angle the story
        is about, before you write the title or a word of the body. Every
        number you then use has to belong to that reading. If you find yourself
        wanting a number from a different reading, the answer is not to add it,
        it is that you may have picked the wrong reading: go back and change
        `reading`, then write to the new one.

        The six, and `reading` takes exactly one of these values:
        - `just_ran`: what the run that landed was, in plain words: its
          distance and effort against what the day asked, with what intent
          says when it exists. The default when no plan covers today.
        - `plan_reason`: the day's plan against what was run, told through the
          distance verdict and intent. Only when get_planned_sessions returns
          today's row.
        - `week_so_far`: where the run puts the week against last week: a gap
          it ended, a week it filled in.
        - `load`: what the run did to the load balance and what that asks of
          the rest of today and the days after.
        - `readiness`: how much the rest of today can take, from
          readiness_ceiling and the vibe.
        - `streak`: consecutive_weeks_active at 3 or more, extended by this
          run.

        TITLE: one short sentence about the run that just landed and what it
        means for the rest of today or the week. Include whether the distance
        met the plan and what intent says, in plain words, when those fields
        exist. Never write a label for the outcome ("intent hit", "too_hard"):
        describe what the run did. A number is not required if the plain read
        is clear on its own.

        NUMBER CEILING: max 5 numbers across title + body combined. This is a
        ceiling, not a target. A comparison is two of them, and a distance, a
        duration or a count of sessions is a number like any other. A number
        you're not using to tell the reading shouldn't be mentioned at all.

        WHERE THE LENGTH COMES FROM. 120-180 words is room for material, not
        for more adjectives. Take it from the data:
        - what the run did against what the day asked, in plain words, keeping
          measured facts separate from any cause the data does not prove;
        - what that means for the rest of today, within the readiness_ceiling,
          and for the days after;
        - what to watch: recovery, hydration, food, mobility, or a clear red
          flag, told inside the story rather than as a closing disclaimer.
        NO PADDING: every sentence carries something from the data that the
        sentences before it didn't. No restating the title, no generic
        recovery wisdom ("rest is important"), no summing up what you already
        said. If you run short, go back to get_planned_sessions and
        get_week_state for more of the day, never to filler.

        SHAPE: the body is 1-2 paragraphs separated by one blank line, 120-180
        words in total. Each paragraph is at least 3 connected sentences and is
        one beat of the same reading: roughly what the run did, then what it
        means for the rest of today and the week. Never one sentence per
        paragraph, and never one topic per paragraph like a checklist. No
        lists, no headings, no other line breaks.

        Keep every suggestion about recovery and the rest of today. Never write
        "if you do run today", "for your next session", or any other same-day
        running option. Do not prescribe a run, distance, pace, or intensity
        after the run has landed.

        The `session_type` output field MUST be `rest`. The run already happened,
        so this field describes what remains of today, not the session that was
        completed.

        Good titles, the shape and not sentences to reuse:
        - "10.0 km done against 6.2 km planned, harder than the easy day asked for."
        - "6.2 km done as planned, and it stayed easy."
        - "the long run is in, and the rest of today is for recovering it."

        A finished day does not need a motivational ending. State what happened,
        set the recovery ceiling, and stop.
        PROMPT;

    public function __construct(
        private readonly Vibe $vibe,
        private readonly TrainingLoad $trainingLoad,
        private readonly VerdictNarrator $verdictNarrator,
        private readonly StructuredChatCaller $caller,
        private readonly RuleBasedNarrationFiller $ruleBasedNarrationFiller,
        private readonly ResolveRunBaselineAction $runBaseline,
        private readonly TrainingBaseline $trainingBaseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
        private readonly DayAnchorResolver $dayAnchors,
        private readonly CitationValidator $citations,
    ) {
    }

    private function plannedToday(User $user, Carbon $asOf): ?PlannedSession
    {
        return PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $asOf->toDateString())
            ->first();
    }

    private function hasDetailedRunToday(User $user, Carbon $asOf): bool
    {
        return Activity::query()
            ->where('user_id', $user->id)
            ->detailed()
            ->whereHas('detail', fn ($query) => $query->startedOn($asOf))
            ->exists();
    }

    public function generate(User $user, ?Carbon $asOf = null): string
    {
        $asOf ??= Carbon::today();
        $context = $this->context($user, $asOf);
        assert(is_string($context['readiness_ceiling']));
        $ceiling = ReadinessCeiling::from($context['readiness_ceiling']);
        $postRun = $context['ran_today'] === true && $this->hasDetailedRunToday($user, $asOf);
        $systemPrompt = $postRun
            ? PlanVerdictRule::into(self::POST_RUN_SYSTEM_PROMPT)
            : self::SYSTEM_PROMPT;

        $decoded = $this->caller->call(
            kind: 'briefing_mascot_voice',
            systemPrompt: $systemPrompt."\n\n".NarratorContinuity::RULE,
            context: $context,
            schemaName: 'TemariMascotVoice',
            requiredKeys: ['reading', 'title', 'body', 'session_type'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $user->id,
                maxTokens: 1800,
                toolbox: $this->toolbox($user, $asOf),
                validator: $postRun
                    ? static fn (array $answer): ?string => OutcomeLabels::complaint(
                        $answer['title']."\n\n".$answer['body'],
                        'the title or body',
                    )
                    : null,
            ),
            propertySchema: $postRun ? self::POST_RUN_READING_PROPERTY_SCHEMA : self::READING_PROPERTY_SCHEMA,
        );

        assert(is_string($decoded['session_type']) && is_string($decoded['title']) && is_string($decoded['body']));
        $sessionType = ReadinessCeiling::tryFrom($decoded['session_type']);
        if ($postRun && $sessionType !== ReadinessCeiling::Rest) {
            Log::warning('narrator.briefing.post_run_session_violation', [
                'user_id' => $user->id,
                'session_type' => $decoded['session_type'],
            ]);

            return $this->ruleBasedNarrationFiller->postRunBriefing($user, $asOf)
                ?? self::clampedVoice(ReadinessCeiling::Rest);
        }
        if ($sessionType === null || $sessionType->rank() > $ceiling->rank()) {
            Log::warning('narrator.briefing.ceiling_violation', [
                'user_id' => $user->id,
                'ceiling' => $ceiling->value,
                'session_type' => $decoded['session_type'],
            ]);

            return self::clampedVoice($ceiling);
        }

        $today = $this->plannedToday($user, $asOf);

        return new NarrationContent(
            $this->citations->keepResolving($decoded['title'], static fn (): bool => false, 'briefing_mascot_voice'),
            $this->citations->keepResolving(
                $decoded['body'],
                fn (string $anchor): bool => $this->dayAnchors->resolves($anchor, $today),
                'briefing_mascot_voice',
            ),
        )->toStored();
    }

    /**
     * @return array<string, mixed>
     */
    public function context(User $user, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::today();
        $previousNarratives = $this->previousDailyNarratives(
            AnalysisType::BRIEFING_SUBJECT_TYPE,
            $user->id,
            AnalysisType::BriefingMascotVoice,
            $asOf,
        );
        $briefing = BriefingContext::forBriefingNarrator($user, $asOf);

        return [
            'name' => $user->firstName(),
            'vibe' => $this->vibe->current($user, $asOf),
            'date' => $asOf->toDateString(),
            'ran_today' => $briefing->ranToday,
            'readiness_ceiling' => $briefing->readinessCeiling,
            'build_nudge' => $briefing->buildNudge,
            ...($briefing->historyLoading ? ['history_loading' => true] : []),
            ...NarratorContinuity::fields($previousNarratives),
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
            ReadinessCeiling::Rest => "rest today.\n\n"
                ."Your load and your recovery both say no, so there's no run going on the "
                ."board. Light mobility if you want to move at all. We pick the scoreboard "
                ."back up tomorrow.",
            ReadinessCeiling::EasyOnly => "easy run, whatever feels comfortable.\n\n"
                ."Today's ceiling is easy and I'm not going to argue with it. If you get a "
                ."slot, keep the pace relaxed and your breathing easy enough to talk. That's "
                ."the whole session, and finishing it counts.",
            ReadinessCeiling::ModerateOk => "easy to moderate base run.\n\n"
                ."You're good for a base or moderate effort. Not tempo, not intervals, not "
                ."today. If you get a slot, hold that effort and leave it there.",
            ReadinessCeiling::QualityOk => "today's session, your call.\n\n"
                ."You're in good shape and everything from easy through quality is on the "
                ."table. Pick the one that actually moves your week, not the one that's "
                ."most comfortable.",
        };
    }

    public function toolbox(User $user, Carbon $asOf): AgentToolbox
    {
        return new AgentToolbox([
            new WeekStateTool($user, $asOf),
            new RecentRunsTool($user, $asOf, $this->verdictNarrator),
            new TrainingLoadTool($user, $asOf, $this->trainingLoad),
            new RecentBaselineTool($user, $asOf, $this->runBaseline),
            new PlanContextTool($user, $asOf, $asOf, $this->trainingBaseline, $this->vdotEstimator, $this->paceCalculator),
        ]);
    }
}
