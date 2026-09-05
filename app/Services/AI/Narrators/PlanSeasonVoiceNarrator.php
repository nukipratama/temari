<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\Season;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanSeasonTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;

class PlanSeasonVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 1-2 sentences introducing this training arc, max 45 words.

        DATA: call get_season before writing. It tells you whether this arc is building toward a
        named race or is self-scaled (no race set), the window it covers, and the season goals it's
        tracking.

        A RACE-ORIENTED season should name the race and roughly how far out it is, in plain terms
        (weeks out, not a raw date). A SELF-SCALED season has no race to build toward: frame it as
        base-building or staying consistent, not as a countdown to nothing. Season goals are there for
        texture (a distance target, a consistency target). Mention at most one if it genuinely helps
        the line land, never list them all.

        Examples:
        - "12 weeks out from jakarta half. this is the arc that gets you there."
        - "no race on the books right now, so this one's about building a base you can trust later."
        - "8 weeks to your marathon. the work from here on is specific."

        ANTI-PATTERN:
        - Listing every season goal: pick at most one, or none.
        - Treating a self-scaled season as directionless. It has a purpose (building fitness), it
          just isn't counting down to a start line.
        - A hype-speech about the race. State the arc, don't sell it.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
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
                toolbox: new AgentToolbox([new PlanSeasonTool($season)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['voice'];
    }
}
