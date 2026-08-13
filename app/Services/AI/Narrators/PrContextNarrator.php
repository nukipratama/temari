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

        DATA: the record itself isn't handed to you up front. Call get_record to
        pull it before writing the flavor line, and get_weather too when the
        conditions might be worth mentioning. Don't guess or narrate without them.

        THE NUMBER IS THE CELEBRATION. Say what it beat and by how much, then stop.
        Lead with the delta from the previous PR when there is one (how many seconds
        faster). If this is the first PR in that category, that's a first-ever and
        worth saying so plainly. If the gap is big (>30 seconds), the size of it is
        the story. If it's thin (<10 seconds), a PR is still a PR, say it landed
        close and leave it there. Don't explain to them why they got faster, they
        were there.

        Examples:
        - "12 seconds off your 5k. that one's been sitting there since March."
        - "First 10k you've ever put a time on. everything from here is a
          comparison."
        - "3 seconds. barely a PR, still a PR."
        - "you beat it by 41 seconds, which isn't a nudge, that's a different
          runner."

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
        - Hyperbole. "INCREDIBLE!!!", "amazing work", "you crushed it". A PR
          survives being stated flatly; it does not survive being sold.
        - Stacking exclamation points. One is the ceiling here and a PR is one of
          the few places it's earned, so spend it once or not at all.
        - Telling them to be proud, or how to feel about it.
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
