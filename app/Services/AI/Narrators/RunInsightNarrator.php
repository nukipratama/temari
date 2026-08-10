<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\HrZonesTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\LapsTool;
use App\Services\AI\Agent\Tools\RecentBaselineTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\TerrainTool;
use App\Services\AI\Agent\Tools\TrainingLoadTool;
use App\Services\AI\Agent\Tools\TrainingPacesTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\AnalysisType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\Narrators\Concerns\ReadsPreviousActivityNarrative;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\RelativeEffort;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;

class RunInsightNarrator
{
    use ReadsPreviousActivityNarrative;

    /** Never render more than this many claims, even if the model returns more valid ones. */
    private const int MAX_CLAIMS = 3;

    /**
     * The `claims` property's JSON-schema override for {@see StructuredChatCaller::call()}.
     * `value`/`delta` are nullable rather than omittable: Azure's strict
     * structured-output mode requires every declared property to be listed as
     * required, so "optional" is expressed as "required, but may be null".
     */
    private const array CLAIMS_PROPERTY_SCHEMA = [
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
        Task: 1-3 short claims about this run, each pinned to one real, specific
        thing in the data. Not three fixed sections anymore: read the run, decide
        what's actually worth pointing out, and surface only that. A flat,
        unremarkable run can collapse to a single claim; an eventful one can carry
        up to three. NEVER pad to 3 when there isn't real material, fewer real
        claims beat manufactured ones.

        DATA: the numbers aren't handed to you up front. Fetch them yourself
        through the available tools, call only what you need, and you can call
        several at once in a single turn. NEVER make up a number you never
        fetched. A field missing or null from a tool result means there's no
        reading for it: it simply is not a candidate for a claim. Don't mention
        that something is missing, just move on to what IS real.

        OUTPUT SHAPE: return "claims", a list of 1-3 objects. Each object:
        - anchor (REQUIRED): pins the claim to the exact real thing it
          describes, one of:
            * "split:<n>" -- n is the 1-indexed km number from get_km_splits'
              per_km (e.g. "split:4" for km 4). Only a km number that tool
              actually returned. Never anchor to the trailing partial/"sisa"
              segment, there is no anchor for it: leave it out of the claims,
              or fold the thought into a metric claim instead.
            * "zone:<z1..z5>" -- one of z1-z5, only when get_hr_zones returned
              a real zone_pct/time_in_zone_min breakdown.
            * "metric:<name>" -- name is EXACTLY one of: decoupling, hr_drift,
              cadence_drop, pace_variability, grade, negative_split, gap_pace.
          A claim whose anchor does not match something you actually fetched
          is dropped before it ever reaches the user, wasting the whole claim,
          so always anchor to a real reading, never a guess.
        - text (REQUIRED): the natural-language observation, 1-2 sentences.
          Explain what the number MEANS, never just state it.
        - value (nullable): the headline number for this claim, formatted the
          way a runner reads it ("5:32/km", "+12%", "148 bpm"). Null when
          there's no single clean number to headline.
        - delta (nullable): a comparison figure when one is genuinely
          meaningful ("-0:08 vs km 3", "+8 bpm vs last week"). Null when
          there's nothing worth comparing against, don't force one.

        INTENSITY MUST BE CONSISTENT: `intensity_label` from get_hr_zones
        (light/moderate/heavy, computed from the HR zone spread) is the ONE
        source of truth for how intense this session was. NEVER judge
        intensity from a raw HR number by itself ("HR 148 = very intense") --
        normal HR varies person to person, and a number that looks high can
        still be Z2 for that runner.

        WEATHER & DECOUPLING: if decoupling is high (>10%) BUT weather_temp_c
        is above 30 degrees, NEVER claim the aerobic base is weak or fitness
        is declining. Frame it as expected given the heat: the heart works
        harder to help the body shed heat, not a sign of lost fitness. If
        decoupling is high and the weather was cool (or there's no weather
        data), that's still the usual signal the aerobic base isn't solid yet.
        * Good: value "+14%", text "Decoupling climbed, but that's the
          32-degree heat talking, not your aerobic base slipping."

        WIND: weather_wind_speed_kmh (speed, km/h) only earns a claim when it
        plausibly explains a pace drop or effort spike tied to a specific
        split. Skip it if it's under ~20 km/h: wind that light isn't worth a
        claim.

        STRUCTURED SESSION: if get_laps gives a rep_count, the laps alternate
        fast-slow, meaning up-and-down pace IS the shape of the session by
        design, not messy pacing. A split-anchored claim about a "slow" km on
        a session like this should read it as the shape of the workout (the
        warmup, the recovery jog between reps -- its length is in
        recovery_sec, or the cooldown) or the reps themselves, never as pacing
        that fell apart.
        * ANTI-PATTERN: "Km 3 slowed to 7:10, pacing wasn't steady." on a
          session whose km 3 was the deliberate recovery jog between reps.

        GREY ZONE: if this session reads as easy/recovery but a lot of the
        time sits in Z3 or above (a "zone:z3" claim or heavier), a gentle
        nudge toward the easy pace is fair (convert easy_pace_sec from
        get_training_paces to minutes:seconds per km if you have it). SKIP
        ENTIRELY if session_intent.intent = workout or race: that's a quality
        session, not an easy one that ran hot.

        QUALITY SESSIONS: if session_intent.intent = workout or race, high
        HR, lots of Z3-Z4, and rising decoupling are EXPECTED and exactly the
        point. A claim about one of these should read it as the quality
        session landing ("threshold work held"), NEVER as fitness slipping or
        fatigue.

        RAIN: if weather_rain is true, check weather_rain_source. "observed"
        is fine to state plainly. "forecast" is just a prediction and might
        not have happened, so hedge ("forecast called for rain") instead of
        stating it as fact.

        HISTORICAL CONTEXT (fetch through tools if needed, skip if it doesn't
        show up): recent_baseline_28d (last 28 days' pace/HR/decoupling
        average) and relative_effort (this session's load vs the 28-day
        average) are good material for a delta ("today's pace 5:30 vs your
        28-day average 5:48" -> delta "-0:18 vs 28d avg"). training_load's
        form/form_status is useful framing for a decoupling or HR-drift claim
        on a fatigued day, never a reason to invent a claim with no real
        number behind it.

        Stay in my (Temari's) point of view, observing the user.

        LANGUAGE: keep it plain and conversational, not clinical ("steady"
        not robotic jargon, "effort" used naturally). Running terms stay
        as-is: easy, tempo, pace, cadence, base, negative split, long run.

        ANTI-PATTERN:
        - A data dump with no interpretation ("cadence 172, HR 148") -- always
          explain what it means.
        - The same claim shape every session. Vary which anchors get picked
          and how the text reads.
        - Lecturing. Observe, don't preach.
        - Padding to 3 claims on a flat run with nothing notable. 1 honest
          claim beats 3 manufactured ones.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingLoad $trainingLoad,
        private readonly ResolveRunBaselineAction $baseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $trainingPaceCalculator,
        private readonly RelativeEffort $relativeEffort,
    ) {
    }

    /**
     * @return array{claims: list<array{anchor: string, text: string, value: string|null, delta: string|null}>}
     */
    public function generate(Activity $activity, ActivityDetail $detail): array
    {
        $decoded = $this->caller->call(
            kind: 'run_insight',
            systemPrompt: self::SYSTEM_PROMPT."\n\n".NarratorContinuity::RULE,
            context: $this->context($activity, $detail),
            schemaName: 'TemariRunInsight',
            requiredKeys: ['claims'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $activity->user_id,
                maxTokens: 3000,
                toolbox: $this->toolbox($activity, $detail),
            ),
            propertySchema: self::CLAIMS_PROPERTY_SCHEMA,
        );

        $claims = is_array($decoded['claims']) ? $decoded['claims'] : [];

        return ['claims' => $this->resolveClaims($claims, $detail)];
    }

    /**
     * Deterministic falsifiability gate: every claim's anchor is checked
     * against this run's own computed data before it is allowed to persist or
     * render, so the LLM cannot narrate a split, zone or metric this run does
     * not actually have. A claim whose anchor does not resolve is dropped
     * silently rather than repaired -- the model is told exactly what a valid
     * anchor looks like, so a bad one is a signal to drop, not to guess at.
     *
     * @param  array<mixed>  $claims  Raw, untrusted model output.
     * @return list<array{anchor: string, text: string, value: string|null, delta: string|null}>
     */
    private function resolveClaims(array $claims, ActivityDetail $detail): array
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
            if (! is_string($anchor) || ! is_string($text) || $text === '' || ! self::anchorResolves($anchor, $summary)) {
                continue;
            }

            $resolved[] = [
                'anchor' => $anchor,
                'text' => $text,
                'value' => is_string($claim['value'] ?? null) ? $claim['value'] : null,
                'delta' => is_string($claim['delta'] ?? null) ? $claim['delta'] : null,
            ];
        }

        return $resolved;
    }

    /**
     * Whether $anchor names something this run's own {@see StreamSummary}
     * actually has, per the anchor namespace: `split:<n>`, `zone:<z1..z5>`,
     * `metric:<name>`.
     */
    private static function anchorResolves(string $anchor, StreamSummary $summary): bool
    {
        if (preg_match('/^split:([1-9]\d*)$/', $anchor, $matches) === 1) {
            return count($summary->perKm() ?? []) >= (int) $matches[1];
        }

        if (preg_match('/^zone:z[1-5]$/', $anchor) === 1) {
            return $summary->zonePct() !== [] || $summary->zoneMinutes() !== null;
        }

        if (preg_match('/^metric:([a-z_]+)$/', $anchor, $matches) === 1) {
            return self::metricResolves($matches[1], $summary);
        }

        return false;
    }

    /** The exhaustive `metric:<name>` set; any other name falls through to false. */
    private static function metricResolves(string $name, StreamSummary $summary): bool
    {
        return match ($name) {
            'decoupling' => $summary->hasDecouplingPct(),
            'hr_drift' => $summary->hrDriftBpm() !== null,
            'cadence_drop' => $summary->cadenceDropSpm() !== null,
            'pace_variability' => $summary->paceVariabilitySec() !== null,
            'grade' => $summary->maxGradePct() !== null,
            'gap_pace' => $summary->gapPace() !== null,
            // A computed bool (true or false) is a real reading; only the
            // absence of the key at all means this run never measured it.
            'negative_split' => $summary->negativeSplit() !== null,
            default => false,
        };
    }

    /**
     * The run's numbers are not passed in — the model reads them through
     * {@see self::toolbox()}. What stays here is the one thing a tool must not
     * serve: the previous run's narrative, which the content-filter retry has
     * to be able to strip from the prompt.
     *
     * @return array<string, mixed>
     */
    public function context(Activity $activity, ActivityDetail $detail): array
    {
        $prevNarrative = $this->previousActivityNarrative(
            $activity,
            $detail,
            AnalysisType::RunInsight,
        );

        return NarratorContinuity::fields($prevNarrative);
    }

    /**
     * The reads this run's narration may pull, each bound to this activity.
     */
    public function toolbox(Activity $activity, ActivityDetail $detail): AgentToolbox
    {
        $asOf = $detail->start_date_local ?? Carbon::now();

        return new AgentToolbox([
            new RunSummaryTool($activity, $detail),
            new KmSplitsTool($activity, $detail),
            new LapsTool($activity, $detail),
            new HrZonesTool($activity, $detail),
            new TerrainTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new EffortContextTool($activity, $detail, $this->relativeEffort),
            new TrainingLoadTool($activity->user, $asOf, $this->trainingLoad),
            new RecentBaselineTool($activity->user, $asOf, $this->baseline, $activity->id),
            new TrainingPacesTool($activity->user, $asOf, $this->vdotEstimator, $this->trainingPaceCalculator),
        ]);
    }
}
