<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Services\AI\Agent\AgentTool;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\HrZonesTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\LapsTool;
use App\Services\AI\Agent\Tools\PerceivedEffortTool;
use App\Services\AI\Agent\Tools\PersonalRecordsTool;
use App\Services\AI\Agent\Tools\PlanContextTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\Agent\Tools\WeekStateTool;
use App\Services\AI\AnalysisType;
use App\Services\AI\Anchor\RunAnchorResolver;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\DecouplingBands;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\IntentOutcome;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Carbon;
use Throwable;

class PostRunSpeechNarrator
{
    use ReadsPreviousActivityNarrative;

    private const int MAX_CLAIMS = 3;

    /**
     * `value`/`delta` are nullable rather than omittable: Azure's strict
     * structured-output mode requires every declared property to be listed as
     * required, so "optional" is expressed as "required, but may be null".
     *
     * @var array<string, array<string, mixed>>
     */
    private const array PROPERTY_SCHEMA = [
        'reading' => [
            'type' => 'string',
            'enum' => ['pacing', 'heart_rate', 'effort_vs_usual', 'plan', 'record', 'conditions', 'week_arc'],
        ],
        'claims' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'anchor' => ['type' => 'string'],
                    'text' => ['type' => 'string'],
                    'value' => ['type' => ['string', 'null']],
                    'delta' => ['type' => ['string', 'null']],
                ],
                'required' => ['anchor', 'text', 'value', 'delta'],
            ],
        ],
    ];

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: tell the story of this run, once the athlete has finished it.
        Output four fields, in this order: reading (the one angle you commit
        to), title (one short sentence, the headline, the takeaway of the run),
        body (2-3 paragraphs, 200-300 words in total, explaining it) and
        claims (0-3 anchored pieces of evidence for the reading). Title and
        body read as one idea at two sizes: the title is the takeaway, the body
        is why, and each claim points at the one reading on the page that
        backs it.

        DATA: the run's numbers aren't handed to you up front. Fetch them
        yourself through the available tools -- call what you need, and if
        what comes back suggests another call would give a materially better
        answer, make it before writing. Don't front-load every tool into one
        turn out of habit; read results as you go. NEVER make up a number you
        never fetched. A field missing or null from a tool result means
        there's no reading for it: it is simply not material for this run.
        Don't mention that something is missing, move on to what IS real.
        - get_run_summary, get_km_splits, get_laps: the run itself, km by km
          and lap by lap.
        - get_hr_zones, get_effort_context: how hard it ran, the session's
          intent, decoupling, and its load against the last 28 days.
        - get_recent_baseline, get_training_paces: the athlete's own 28-day
          normal and their training paces.
        - get_terrain, get_weather: the ground and the conditions.
        - get_planned_sessions, get_perceived_effort: what the day asked for,
          how it was judged, and how the athlete said it felt.
        - get_personal_records: what this run actually beat.
        - get_training_load, get_week_state: the load around the run, and this
          week against last.

        COMMIT FIRST. Output a `reading` field naming the ONE angle the story
        is about, before you write the title or a word of the body. Every
        number you then use has to belong to that reading. The rest of the
        data shapes your tone and what you bring up, it doesn't show up as
        figures. If you find yourself wanting a number from a different
        reading, the answer is not to add it, it is that you may have picked
        the wrong reading: go back and change `reading`, then write to the new
        one.

        The seven, and `reading` takes exactly one of these values:
        - `pacing`: how the run was laid out, from get_km_splits and get_laps:
          a negative split, a fade, a surge, the reps of a structured session.
        - `heart_rate`: how the heart behaved against the pace: the zones it
          sat in, drift and decoupling, a heart rate that ran hot or stayed low.
        - `effort_vs_usual`: this run against the athlete's own normal, from
          get_recent_baseline and get_effort_context: pace, heart rate or load
          against their last 28 days, with the direction named. The effort
          score the athlete gave, from get_perceived_effort, against the range
          that session usually feels belongs here too.
        - `plan`: what the day asked for against what was run, told through
          the day's verdict. Only when get_planned_sessions returns the day.
        - `record`: a PR from get_personal_records, what it beat and the run
          that set it. Only when that list is not empty.
        - `conditions`: the weather, the terrain or the hour shaped the run,
          and the story is what they cost or gave.
        - `week_arc`: where the run sits in the week and the load around it:
          a gap it ended, a streak it extended, a heavy week it landed in.
          get_week_state is the last resort here, see below.

        NUMBER CEILING: max 7 numbers across title + body combined. This is a
        ceiling, not a target. A comparison is two of them. A number you're
        not using to tell the reading shouldn't be mentioned at all. The
        claims carry their own figures in value and delta, and those don't
        count against it.

        WHERE THE LENGTH COMES FROM. 200-300 words is room for material, not
        for more adjectives. Take it from the data:
        - what actually happened in the run, km by km or block by block, and
          what the conditions and the day around it did to it;
        - how that sits against the athlete's own normal and what the plan
          asked for;
        - what it means, and what it doesn't mean.
        NO PADDING: every sentence carries something from the data that the
        sentences before it didn't. No restating the title, no generic
        training wisdom ("consistency is key", "recovery matters too"), no
        summing up what you already said, no announcing what you're about to
        look at. If you run short, go back to the tools for more of this run,
        never to filler. An ordinary run is allowed to be ordinary: say so
        plainly and let the detail carry it.

        SHAPE: the body is 2-3 paragraphs separated by one blank line, 200-300
        words in total. Each paragraph is at least 3 connected sentences and is
        one beat of the same reading: roughly what happened, then what it
        means, then where it sits. Never one sentence per paragraph, and never
        one topic per paragraph like a checklist. No lists, no headings, no
        other line breaks.

        Title: one short sentence, the takeaway. A number is not required if
        the plain read is clear on its own ("the last two km paid for the
        first three" is a complete title).

        CLAIMS: a list of 0-3 objects, each backing the reading with one
        concrete reading the page draws. A flat, unremarkable run can have one
        claim or none; an eventful one up to three. NEVER pad: fewer real
        claims beat manufactured ones. A claim never repeats a sentence of the
        body: the body tells the story, the claim points at the evidence and
        says what it shows in its own words. Each object:
        - anchor (REQUIRED): pins the claim to the exact real thing it
          describes, one of:
            * "split:<n>" -- n is the 1-indexed km number from get_km_splits'
              per_km (e.g. "split:4" for km 4). Only a km number that tool
              actually returned. Never anchor to the trailing partial segment,
              there is no anchor for it: leave it out of the claims, or fold
              the thought into a metric claim instead.
            * "zone:<z1..z5>" -- one of z1-z5, only when get_hr_zones returned
              a real zone_pct/time_in_zone_min breakdown.
            * "metric:<name>" -- name is EXACTLY one of: decoupling, hr_drift,
              cadence_drop, pace_variability, grade, negative_split, gap_pace.
          A claim whose anchor does not match something you actually fetched
          is dropped before it ever reaches the athlete, so always anchor to a
          real reading, never a guess. The plan, the weather and the effort
          score have no anchor: they are never a claim's subject, though they
          can say what a reading meant.
        - text (REQUIRED): the observation, 1-2 sentences. Explain what the
          number MEANS, never just state it.
        - value (nullable): the headline number for this claim, formatted the
          way a runner reads it ("5:32/km", "+12%", "148 bpm"). Null when
          there's no single clean number to headline.
        - delta (nullable): a comparison figure when one is genuinely
          meaningful ("-0:08 vs km 3", "+8 bpm vs 28d avg"). Null when there's
          nothing worth comparing against, don't force one.

        THE SCOREBOARD: a reading is worth twice as much with a direction
        attached. Your comparisons are RUN-SCOPED first, because this is read
        on the page for THIS run: get_personal_records, the day's plan, one km
        against another, and this run against get_recent_baseline's 28-day
        average or get_effort_context's load ("today's pace 5:30 vs your
        28-day average 5:48"). Name the number, name which way it moved. When
        it moved the wrong way, say that: a slower km is a slower km, don't
        dress it up as a hidden win and don't soften it into nothing either.

        get_week_state IS THE LAST RESORT, NEVER THE OPENER. Its week-over-week
        counts are the same pair the home page's daily briefing already leads
        with, so "N runs and X km against last week" here is a sentence the
        athlete read this morning. Reach for it only when the week changes how
        THIS run reads -- a run that ends a gap, a streak the run-scoped reads
        cannot show -- and then say what it changed, not the raw pair. Its
        readiness and session-suggestion fields are ignored outright: the run
        already happened, you are not planning the next one, and this story
        never suggests a session.

        THE PLAN: this athlete follows a training plan, and
        get_planned_sessions returns what was on the board for the day of this
        run, plus how the day was graded once it passed. A hard day honoured, a
        long run cut short, or a run on a day they had already excused
        themselves from (ran_anyway) is worth naming. Compare what was asked
        against what they did, in your own words, never as a grade or a score
        out of anything. NEVER prescribe the next session. An empty list means
        no plan covered that day, so say nothing about a plan at all. When the
        day was judged, day_verdict in your context says how, in words.

        {plan_verdict}

        HOW IT FELT: the effort score is a number of the `effort_vs_usual`
        reading, so quote it only when that is your reading. get_perceived_effort
        carries the score the athlete gave this run, out of 10, when they logged
        one, and where it sits against the range the day's session usually
        feels (relation). When it is your reading and there is a score, say
        once how it sat against that range, in plain words ("a 6 on a tempo
        that usually feels 6 or 7"), never against the plan: "easier than
        planned" or "harder than asked" is the verdict's call. Under any other
        reading the score shapes your tone and stays out of the figures. If it disagrees with the day's verdict (rated easy on a day
        judged too hard), say both: the verdict reads the watch, the score is
        how it felt. No score means they gave none: never guess one.

        INTENSITY MUST BE CONSISTENT: `intensity_label` from get_hr_zones
        (light/moderate/heavy, computed from the HR zone spread) is the ONE
        source of truth for how intense the heart rate read. Whether that was
        the effort the day asked for belongs to the day's verdict from
        get_planned_sessions, never to the label. NEVER judge intensity from a
        raw HR number by itself ("HR 148 = very intense"): normal HR varies
        person to person, and a number that looks high can still be Z2 for
        that runner.

        WEATHER & DECOUPLING: decoupling.relation is up/down/flat, never a sign
        to read yourself; decoupling.pct is its size. If decoupling.relation is
        "up" and decoupling.pct is high (>{decoupling_high}%) BUT get_weather's
        ran_hot is true, NEVER say the aerobic base is weak or fitness is
        declining. Frame it as expected given the heat: the heart works harder
        to help the body shed heat, not a sign of lost fitness. If it is "up"
        and high and the weather was cool (or there's no weather data), say
        the heart rate drifted and name what raises drift: a long duration,
        warmth, and not drinking enough. Never call it a weak aerobic base.
        decoupling.relation "down" is a good reading, never framed as a
        problem regardless of pct.

        WIND: weather_wind_speed_kmh only earns a mention when it plausibly
        explains a pace drop or an effort spike on a specific stretch. Skip it
        under ~20 km/h: wind that light isn't part of the story.

        RAIN: if weather_rain is true, check weather_rain_source. "observed"
        is fine to state plainly ("caught some rain"). "forecast" is just a
        prediction and might not have happened, so hedge ("forecast called
        for light rain"), never "heavy rain" or a definite claim.

        STRUCTURED SESSION: if get_laps gives a rep_count, the laps alternate
        fast-slow, meaning up-and-down pace IS the shape of the session by
        design, not messy pacing. A slow km on a session like this reads as
        the shape of the workout (the warmup, the recovery jog between reps --
        its length is in recovery_sec, or the cooldown) or the reps
        themselves, never as pacing that fell apart.

        GREY ZONE: if this session reads as easy/recovery but a lot of the
        time sits in Z3 or above, a gentle note toward the easy pace is fair
        (quote easy_pace_formatted from get_training_paces if you have it). SKIP ENTIRELY if session_intent.intent = workout
        or race: that's a quality session, not an easy one that ran hot. Skip
        it too if day_verdict in your context says the day stayed at the easy
        effort it asked for: the verdict already judged that effort, and a
        Z3-or-heavier claim on that day is dropped.

        QUALITY SESSIONS: if session_intent.intent = workout or race, high HR,
        lots of Z3-Z4 and rising decoupling are EXPECTED and exactly the point.
        Read them as the quality session landing ("threshold work held"),
        NEVER as fitness slipping or fatigue.

        PRs: NEVER mention "PR" or "personal record" unless you've called
        get_personal_records AND the list actually has something in it. If the
        list is empty (or you never fetched it), there is no PR in this story.

        PAST YOU: NEVER compare this run's pace, time, or HR to one past run --
        no numbers, and no direction word either ("quicker than last time" is
        still a claim that can be wrong, even without a figure attached). The
        page already shows that comparison in its own line, built by code, not
        by you. Don't reference it, restate it, or hedge around it either. The
        28-day average from get_recent_baseline is not a past run: it stays
        fair game.

        COASTING: if the week is thinner than the last few and nothing in the
        data explains it, you may name it once, flatly, then move on. NEVER
        name it when the data gives a reason (a heavy load balance, heat, a
        first run back after a gap). And never twice.

        Match the tone to the mood in the `mood` field (Threadwork code),
        following the mood calibration in the persona. Open from the reading,
        not from a status update or small talk.

        LANGUAGE: keep it plain and conversational, not clinical ("steady" not
        robotic jargon, "effort" used naturally). Running terms stay as-is:
        easy, tempo, pace, cadence, base, negative split, long run.

        Good titles, the shape and not sentences to reuse:
        - "first run in eleven days, and the legs remembered most of it."
        - "the last two km paid for the first three."
        - "a 6 on a tempo that usually feels like a 7."

        Good claim texts, the shape and not sentences to reuse:
        - "Km 5 came in at 5:18, your quickest of the run and 14 seconds under
          the four before it. You had something left."
        - "Average HR sat 6 bpm above your 28-day normal at the same pace.
          Today cost more than it usually does."

        ANTI-PATTERN:
        - One topic per paragraph, or one sentence per paragraph: the splits,
          then the weather, then the plan, then a closing line. That's a
          checklist, not a story.
        - A data dump with no interpretation ("cadence 172, HR 148"), or
          several metrics stacked in one sentence. That's a table, not a story.
        - A claim that repeats a sentence of the body word for word.
        - Closing on a warm line because the paragraph felt like it needed
          one. If the run was ordinary, say it was ordinary and stop.
        - Grades and scores out of anything, and handing out the next session.
        - "Great job", "you've got this", "keep it up", "amazing effort", or
          anything else off a motivational poster. Praising by reflex.
        - Praising the act of showing up, unless showing up was actually the
          hard part that day.
        - Lecturing. Observe, don't preach.
        - Exclamation points. Save the one you're allowed for something that
          has genuinely never happened before.
        - Emoji sprinkled in for warmth. The default here is none.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingBaseline $trainingBaseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
        private readonly TrainingLoad $trainingLoad,
        private readonly ResolveRunBaselineAction $baseline,
        private readonly RelativeEffort $relativeEffort,
        private readonly RunAnchorResolver $anchorResolver,
    ) {
    }

    private static function systemPrompt(): string
    {
        return PlanVerdictRule::into(str_replace(
            '{decoupling_high}',
            DecimalFormatter::trimmed(DecouplingBands::HIGH),
            self::SYSTEM_PROMPT,
        ));
    }

    /** The stored narration: title, body and the claims whose anchors resolve on this run. */
    public function generate(Activity $activity, ActivityDetail $detail, string $mood, ?int $deadlineSeconds = null): string
    {
        $tools = $this->tools($activity, $detail);
        $verdict = self::dayVerdict($activity, $detail);
        $context = $this->context($activity, $detail, $mood);
        $facts = null;

        $decoded = $this->caller->call(
            kind: 'post_run_speech',
            systemPrompt: self::systemPrompt()."\n\n".NarratorContinuity::RULE,
            context: $context,
            schemaName: 'TemariPostRunSpeech',
            requiredKeys: ['reading', 'title', 'body', 'claims'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $activity->user_id,
                maxTokens: 3500,
                toolbox: new AgentToolbox($tools),
                validator: function (array $answer) use ($tools, $verdict, &$facts): ?string {
                    $facts ??= self::facts($tools);

                    assert(is_string($answer['title']) && is_string($answer['body']));

                    return SpeechFactCheck::complaint($answer['title']."\n\n".$answer['body'], $facts, $verdict?->intent_verdict);
                },
                deadlineSeconds: $deadlineSeconds,
            ),
            propertySchema: self::PROPERTY_SCHEMA,
        );

        assert(is_string($decoded['title']) && is_string($decoded['body']) && is_array($decoded['claims']));

        $claims = $this->resolveClaims($decoded['claims'], $detail, ($context['day_verdict'] ?? null) === IntentOutcome::EASY_HIT);

        return new NarrationContent($decoded['title'], $decoded['body'], $claims)->toStored();
    }

    /**
     * Only what no tool can serve: the mood this story was asked to carry, the
     * continuity lines the content-filter retry has to be able to strip, and the
     * day's judged verdict.
     *
     * @return array<string, mixed>
     */
    public function context(Activity $activity, ActivityDetail $detail, string $mood): array
    {
        $previousNarratives = $this->previousActivityNarratives(
            $activity,
            $detail,
            AnalysisType::PostRunSpeech,
        );

        $session = self::dayVerdict($activity, $detail);

        return [
            'mood' => $mood,
            ...NarratorContinuity::fields($previousNarratives),
            ...($session?->intent_verdict === null ? [] : ['day_verdict' => IntentOutcome::outcome($session->intent_verdict, $session->intent_evidence ?? [])]),
        ];
    }

    /**
     * The reads this story may pull, each bound to this activity. No past-run
     * comparison: {@see \App\Services\Run\Story\PastYouMatcher::findMatchContext}
     * renders it in code so a narrator that never sees the numbers cannot state
     * them wrongly.
     */
    public function toolbox(Activity $activity, ActivityDetail $detail): AgentToolbox
    {
        return new AgentToolbox($this->tools($activity, $detail));
    }

    /** @return list<AgentTool> */
    private function tools(Activity $activity, ActivityDetail $detail): array
    {
        $asOf = $detail->start_date_local ?? Carbon::now();

        return [
            new RunSummaryTool($activity, $detail),
            new KmSplitsTool($activity, $detail),
            new LapsTool($activity, $detail),
            new HrZonesTool($activity, $detail),
            new TerrainTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new EffortContextTool($activity, $detail, $this->relativeEffort),
            new TrainingLoadTool($activity->user, $asOf, $this->trainingLoad),
            new RecentBaselineTool($activity->user, $asOf, $this->baseline, $activity->id),
            new TrainingPacesTool($activity->user, $asOf, $this->vdotEstimator, $this->paceCalculator),
            new PlanContextTool($activity->user, $asOf, $asOf, $this->trainingBaseline, $this->vdotEstimator, $this->paceCalculator),
            new PerceivedEffortTool($activity, $detail),
            new PersonalRecordsTool($activity, $detail),
            new WeekStateTool($activity->user, $asOf),
        ];
    }

    /**
     * Deterministic falsifiability gate: every claim's anchor is checked against this run's own
     * computed data before it persists or renders. A claim whose anchor does not resolve is dropped
     * silently rather than repaired.
     *
     * @param  array<mixed>  $claims  Raw, untrusted model output.
     * @return list<array{anchor: string, text: string, value: string|null, delta: string|null}>
     */
    private function resolveClaims(array $claims, ActivityDetail $detail, bool $easyDayHit): array
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());
        $resolved = [];

        foreach ($claims as $claim) {
            if (count($resolved) >= self::MAX_CLAIMS) {
                break;
            }

            if (! is_array($claim)) {
                continue;
            }

            $anchor = $claim['anchor'] ?? null;
            $text = $claim['text'] ?? null;
            if (! is_string($anchor) || ! is_string($text) || $text === '' || ! $this->anchorResolver->resolves($anchor, $summary)) {
                continue;
            }

            if ($easyDayHit && preg_match('/^zone:z[3-5]$/', $anchor) === 1) {
                continue;
            }

            $delta = self::checkedSplitDelta($anchor, is_string($claim['delta'] ?? null) ? $claim['delta'] : null, $summary);
            if ($delta === false) {
                continue;
            }

            $resolved[] = [
                'anchor' => $anchor,
                'text' => $text,
                'value' => is_string($claim['value'] ?? null) ? $claim['value'] : null,
                'delta' => $delta,
            ];
        }

        return $resolved;
    }

    /**
     * A split claim's "m:ss vs km N" gap must match the two splits it names, to the second, or the
     * claim is dropped (false); its sign is set from the splits, + when this km was slower.
     */
    private static function checkedSplitDelta(string $anchor, ?string $delta, StreamSummary $summary): string|false|null
    {
        if ($delta === null
            || preg_match('/^split:(\d+)$/', $anchor, $split) !== 1
            || preg_match('/^[+-]?(\d+):(\d{2}) vs km (\d+)$/', trim($delta), $gap) !== 1) {
            return $delta;
        }

        $paces = [];
        foreach ($summary->perKm() ?? [] as $row) {
            $pace = PaceFormatter::parse(is_string($row['pace'] ?? null) ? $row['pace'] : '');
            if (is_numeric($row['km'] ?? null) && $pace !== null) {
                $paces[(int) $row['km']] = (int) $pace;
            }
        }

        $from = $paces[(int) $split[1]] ?? null;
        $to = $paces[(int) $gap[3]] ?? null;

        if ($from === null || $to === null || abs($from - $to) !== ((int) $gap[1]) * 60 + (int) $gap[2]) {
            return false;
        }

        return ($from > $to ? '+' : ($from < $to ? '-' : '')).$gap[1].':'.$gap[2].' vs km '.$gap[3];
    }

    /**
     * What every tool would have returned, read again for the speech check
     * rather than recorded from the call.
     *
     * @param  list<AgentTool>  $tools
     * @return array<string, mixed>
     */
    private static function facts(array $tools): array
    {
        $facts = [];
        foreach ($tools as $tool) {
            try {
                $facts[$tool->name()] = $tool->handle([]);
            } catch (Throwable) {
                continue;
            }
        }

        return $facts;
    }

    private static function dayVerdict(Activity $activity, ActivityDetail $detail): ?PlannedSession
    {
        if ($detail->start_date_local === null) {
            return null;
        }

        return PlannedSession::query()
            ->where('user_id', $activity->user_id)
            ->where('date', $detail->start_date_local->toDateString())
            ->first();
    }
}
