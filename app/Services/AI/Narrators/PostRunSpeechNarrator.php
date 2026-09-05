<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PastYouTool;
use App\Services\AI\Agent\Tools\PersonalRecordsTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\PastYouMatcher;
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
        (compared to similar past sessions, compared to records, compared to the
        last run), and how it felt. They answer "what happened"; you answer "why
        this run mattered".

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
        just not on splits and zones. Your comparisons are get_past_you (a similar
        run they already did), get_personal_records (what this run actually beat),
        and get_week_state (runs and km this week against last week, how many weeks
        running they've kept it up). Name the number and the direction. If it went
        the wrong way, say it went the wrong way: fewer runs than last week is fewer
        runs than last week.

        get_week_state also carries readiness and session-suggestion fields. Ignore
        those entirely. The run already happened, you are not planning the next one,
        and this block never suggests a session. What you want out of it is the
        week-over-week counts and the streak.

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

        PAST YOU: if `past_you` from get_past_you is populated (a similar run exists
        in the past), fine to use it as a personal opening or closing hook, e.g.
        "compared to a similar session {days_ago} days ago, your pace is
        {pace_diff_sec} seconds faster". pace_diff_sec and time_diff_sec positive =
        FASTER now, negative = slower (be honest about it, don't spin it as always
        winning). hr_diff_bpm positive = HR is higher now. If `past_you` is null,
        NEVER make up a comparison to the past.

        Good examples of the range this block should cover:
        - "third run this week, one more than the whole of last week. the habit is
          doing the work now, not the motivation."
        - "You've run this loop before, 41 days ago, 12 seconds per km slower. same
          legs, different engine."
        - "wet, dark, and you went anyway. that's the whole story of this one."
        - "slower than the last time you ran this far, by about 20 seconds a km. some
          days the route wins."

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
        private readonly PastYouMatcher $pastYou,
        private readonly TrainingLoad $trainingLoad,
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
     * others cannot hold: where this run sits against the athlete's own
     * history, and what the day around it was like.
     */
    public function toolbox(Activity $activity, ActivityDetail $detail): AgentToolbox
    {
        return new AgentToolbox([
            new RunSummaryTool($activity, $detail),
            new TerrainTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new PersonalRecordsTool($activity, $detail),
            new PastYouTool($activity, $detail, $this->pastYou),
            new WeekStateTool($activity->user, $detail->start_date_local ?? Carbon::now(), $this->trainingLoad),
        ]);
    }
}
