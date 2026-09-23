<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Season;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanSeasonTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Plan\SustainedAheadOfRacePace;

class PlanSeasonVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 1-2 sentences introducing this training arc, max 45 words (max 65 when the
        goal-may-be-conservative note below fires).

        DATA: call get_season before writing. It tells you whether this arc is building toward a
        named race or is self-scaled (no race set), the window it covers, the season goals it's
        tracking, whether the athlete has been sustained_ahead_of_race_pace, and the recorded
        adjustment for the current week.

        When current_week_adaptation is present and its reason is not "steady", let that adjustment
        shape the line briefly (for example, a deload or held quality work). Do not invent a cause
        beyond the fields returned by the tool, and do not mention it when its reason is "steady".

        A RACE-ORIENTED season should name the race and roughly how far out it is, in plain terms
        (weeks out, not a raw date). A SELF-SCALED season has no race to build toward: frame it as
        base-building or staying consistent, not as a countdown to nothing. Season goals are there for
        texture (a distance target, a consistency target). Mention at most one if it genuinely helps
        the line land, never list them all.

        GOAL MAY BE CONSERVATIVE: when get_season reports sustained_ahead_of_race_pace is true, add
        one short observation after the arc intro: the athlete has been beating their goal time for a
        couple of weeks running, so the number they set might be short of what they can actually do.
        Raise it as something worth sitting with, never as a decision already made — no revised time,
        no new pace, no "change it to X". This is Temari noticing, not Temari prescribing. When
        sustained_ahead_of_race_pace is false, say nothing about the goal at all — a single fast week
        is not the signal, and this line never fires on a self-scaled season either.

        Examples:
        - "12 weeks out from jakarta half. this is the arc that gets you there."
        - "no race on the books right now, so this one's about building a base you can trust later."
        - "8 weeks to your marathon. the work from here on is specific."
        - "10 weeks out from jakarta half. you've been sitting inside your goal time for a couple of
          weeks now, so that number might be worth revisiting — no rush, just something to sit with."

        ANTI-PATTERN:
        - Listing every season goal: pick at most one, or none.
        - Treating a self-scaled season as directionless. It has a purpose (building fitness), it
          just isn't counting down to a start line.
        - A hype-speech about the race. State the arc, don't sell it.
        - Naming a specific revised goal time or pace, or saying the goal WILL change. Observe, invite,
          stop there.
        - Raising the goal after only one ahead-of-pace week: wait for get_season to say it held.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly SustainedAheadOfRacePace $sustainedAheadOfRacePace,
    ) {
    }

    public function generate(Season $season): string
    {
        $decoded = $this->caller->call(
            kind: 'plan_season_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [],
            schemaName: 'TemariPlanSeasonVoice',
            requiredKeys: ['voice'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $season->user_id,
                maxTokens: 400,
                toolbox: new AgentToolbox([new PlanSeasonTool($season, $this->sustainedAheadOfRacePace)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['voice'];
    }
}
