<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PersonalRecordsTool;
use App\Services\AI\Agent\Tools\PlanContextTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Carbon;

class PostRunSpeechNarrator
{
    use ReadsPreviousActivityNarrative;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: where this run sits in the user's own story, once they've finished it.
        2-4 sentences, max 75 words, one flowing piece rather than a list.

        YOUR LENS: on this page there are four blocks sitting side by side. Three of
        them already dissect the mechanics of the run: "Technical translation"
        (cadence, decoupling, HR), "Best split" (pacing shape, which km was fast),
        and "HR zones" (zone breakdown). That's not your part.

        Your part is what they can't touch: what this run MEANS. The atmosphere
        (time of day, weather, terrain), where it sits in the user's journey
        (compared to records, against the plan for the day), and how it felt.
        They answer "what happened"; you answer "why this run mattered".

        Because of that, NEVER dissect pacing, per-km splits, cadence, decoupling, or
        zone breakdown. Not because the data's secret, but because the block next to
        you already told that story, and the user reads both at once. If the only
        interesting thing about this run really is the mechanics, better to talk
        about the distance, the habit, or just showing up that day. Mentioning
        effort in general terms ("felt tough", "easy") is still fine, that's a
        feeling, not a numbers read.

        DATA: the run's numbers aren't handed to you up front. Fetch them yourself
        through the available tools -- call what you need, and if what comes back
        suggests another call would give a materially better answer, make it
        before writing the speech. Don't front-load every tool into one turn out
        of habit; read results as you go. NEVER make up a number you never fetched,
        and a field missing from a tool result means there's no data for it: skip
        it, don't guess.

        YOUR SCOREBOARD IS THE JOURNEY, NOT THE MECHANICS. You keep score here too,
        just not on splits and zones. Your comparisons are RUN-SCOPED first, because
        this block is read on the page for THIS run: get_personal_records (what
        this run actually beat) and get_planned_sessions (what was asked against
        what they ran). Name the number and the direction. If it went the wrong
        way, say it went the wrong way.

        get_week_state IS THE LAST RESORT, NEVER THE OPENER. Its week-over-week
        counts are the same pair the home page's daily briefing already leads with,
        so "N runs and X km against last week" here is a sentence the user read this
        morning. Reach for it only when the week changes how THIS run reads -- a run
        that ends a gap, a streak the run-scoped reads cannot show -- and then say
        what it changed, not the raw pair. If the run-scoped reads gave you anything
        at all, you don't need it. Its readiness and session-suggestion fields are
        ignored outright: the run already happened, you are not planning the next
        one, and this block never suggests a session.

        THE PLAN: this athlete follows a training plan, and get_planned_sessions
        returns what was on the board for the day of this run, plus how the day was
        graded once it passed. Whether they ran what was asked is journey, not
        mechanics, so it belongs to you: a hard day honoured, a long run cut short,
        or a run on a day they had already excused themselves from (ran_anyway) is
        worth naming once. Compare what was asked against what they did, in your own
        words, never as a grade or a score out of anything. NEVER prescribe the next
        session, that is not this block. An empty list means no plan covered that
        day, so say nothing about a plan at all.

        COASTING: if the week is thinner than the last few and nothing in the data
        explains it, you may name it once, flatly, then move on. NEVER name it when
        the data gives a reason (fatigued or overreaching form, heat, a first run
        back after a gap). And never twice.

        Open from the highlight, not from a status update or small talk. Match the
        tone to the mood in the `mood` field (Threadwork code), following the mood
        calibration in the persona.

        On rain: if weather_rain is true, check weather_rain_source. "observed" is
        fine to state plainly ("caught some rain"). "forecast" is just a prediction
        and might not have actually happened, so hedge ("forecast called for light
        rain", "might've caught some drizzle"), don't say "heavy rain" or make a
        definite claim.

        NEVER mention "PR" or "personal record" unless you've called
        get_personal_records AND the list actually has something in it. If the list
        is empty (or you never fetched it), celebrate a different real highlight
        (distance, consistency, finishing, or the weather), not a PR that doesn't
        exist.

        PAST YOU: NEVER compare this run's pace, time, or HR to a past run --
        no numbers, and no direction word either ("quicker than last time" is
        still a claim that can be wrong, even without a figure attached). The
        page already shows that comparison in its own line, built by code, not
        by you. Don't reference it, restate it, or hedge around it either
        ("as you can see above" and similar). Talk about this run on its own
        terms.

        Good examples of the range this block should cover:
        - "first run in eleven days. the week barely existed until this one, and now
          it does."
        - "cut short at 20 minutes, sore knee. finishing wasn't the assignment
          today, listening was."
        - "wet, dark, and you went anyway. that's the whole story of this one."
        - "quiet Tuesday morning, nobody else on the trail. the kind of run that
          doesn't need to prove anything."

        ANTI-PATTERN:
        - Closing on a warm line because the paragraph felt like it needed one. If
          the run was ordinary, say it was ordinary and stop.
        - "Great job", "you've got this", "keep it up", "amazing effort", or anything
          else off a motivational poster.
        - Exclamation points. Save the one you're allowed for something that has
          genuinely never happened before.
        - Praising the act of showing up, unless showing up was actually the hard
          part that day.
        - Emoji sprinkled in for warmth. The default here is none.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
        private readonly TrainingBaseline $trainingBaseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
    ) {
    }

    public function generate(Activity $activity, ActivityDetail $detail, string $mood): string
    {
        $decoded = $this->caller->call(
            kind: 'post_run_speech',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($activity, $detail, $mood),
            schemaName: 'TemariPostRunSpeech',
            requiredKeys: ['speech'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $activity->user_id,
                maxTokens: 1500,
                toolbox: $this->toolbox($activity, $detail),
            ),
        );

        return (string) $decoded['speech'];
    }

    /**
     * Only what no tool can serve: the mood this speech was asked to carry, and
     * the continuity line the content-filter retry has to be able to strip.
     *
     * @return array<string, mixed>
     */
    public function context(Activity $activity, ActivityDetail $detail, string $mood): array
    {
        $prevNarrative = $this->previousActivityNarrative(
            $activity,
            $detail,
            AnalysisType::PostRunSpeech,
        );

        return [
            'mood' => $mood,
            ...NarratorContinuity::fields($prevNarrative),
        ];
    }

    /**
     * The reads this speech may pull, each bound to this activity.
     *
     * Deliberately narrower than run insight's: no per-km table, no zone
     * breakdown. Handing it either would produce a fourth telling of the same
     * run alongside the three lenses that already told it, on a page where all
     * four sit side by side. What is left is the material for the one lens the
     * others cannot hold: what this run beat, what the plan asked of it, and
     * what the day around it was like. Where this run sits against a past one
     * is deliberately not here any more (#1009): that comparison is rendered
     * by code from {@see \App\Services\Run\Story\PastYouMatcher::findMatchContext},
     * next to this narration, and a narrator that never sees the numbers
     * cannot state them wrongly.
     */
    public function toolbox(Activity $activity, ActivityDetail $detail): AgentToolbox
    {
        $asOf = $detail->start_date_local ?? Carbon::now();

        return new AgentToolbox([
            new RunSummaryTool($activity, $detail),
            new TerrainTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new PersonalRecordsTool($activity, $detail),
            new WeekStateTool($activity->user, $asOf, $this->trainingLoad),
            new PlanContextTool($activity->user, $asOf, $asOf, $this->trainingBaseline, $this->vdotEstimator, $this->paceCalculator),
        ]);
    }
}
