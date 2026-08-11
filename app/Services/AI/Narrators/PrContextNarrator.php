<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PersonalRecord;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PersonalRecordTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\VdotEstimator;

class PrContextNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 1-2 flavor sentences for a Personal Record, max 35 words.

        Highlight the delta from the previous PR if there is one (state how many
        seconds faster). If this is the first PR in that category, celebrate it as a
        "first-ever PR". If the gap is big (>30 seconds), call it out as a big leap.
        If it's thin (<10 seconds), acknowledge the consistent effort.

        Examples:
        - "5km PR cut by 12 seconds from last time. Not a fluke, that's the result
          of consistent training."
        - "First-ever PR at 10km! A big step, you earned that."
        - "Cut it thin, just 3 seconds, but a PR's a PR. Momentum's building."

        Tone: proud, warm, not over the top.

        WEATHER: if conditions during the PR were extreme (weather_temp_c high,
        above 30, or weather_rain true), fine to mention it for extra weight ("PR in
        32-degree heat, respect"). weather_rain_source "forecast" is just a
        prediction, so hedge. If it was calm, skip it, don't force it.

        STRONGEST EVENT: if is_strongest_event is true, this PR also makes this
        category the user's strongest event (highest VDOT across all distances).
        Fine to acknowledge as a point of pride, mention the vdot score if it reads
        well ("this is your strongest event now, VDOT 45"). If false or vdot isn't
        present, never mention VDOT at all.

        ANTI-PATTERN:
        - "The PR is the result of the past few weeks' consistency, not a
          coincidence." -- a formula that keeps showing up.
        - Hyperbole ("INCREDIBLE!!!").
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly VdotEstimator $vdotEstimator,
    ) {
    }

    public function generate(PersonalRecord $pr): string
    {
        $decoded = $this->caller->call(
            kind: 'pr_context',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $this->context($pr),
            schemaName: 'TemariPrContext',
            requiredKeys: ['flavor'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $pr->user_id,
                maxTokens: 500,
                toolbox: $this->toolbox($pr),
                maxSteps: 6,
            ),
        );

        return (string) $decoded['flavor'];
    }

    /**
     * Nothing: the record and the conditions it was set in are both reads.
     *
     * @return array<string, mixed>
     */
    public function context(PersonalRecord $pr): array
    {
        return [];
    }

    public function toolbox(PersonalRecord $pr): AgentToolbox
    {
        $pr->loadMissing('activity.detail');
        $activity = $pr->activity;
        $detail = $activity?->detail;

        $tools = [new PersonalRecordTool($pr, $this->vdotEstimator)];
        if ($activity !== null && $detail !== null) {
            $tools[] = new WeatherTool($activity, $detail);
        }

        return new AgentToolbox($tools);
    }
}
