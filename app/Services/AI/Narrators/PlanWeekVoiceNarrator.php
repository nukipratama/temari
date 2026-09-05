<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PlanAdaptation;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanWeekTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;

class PlanWeekVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 1-2 sentences narrating why this week's plan looks the way it does, max 40 words.

        DATA: call get_week_adaptation before writing. It hands you the periodizer's own verdict
        already reduced to a headline and a detail sentence: your job is to turn that into warmer,
        more natural prose in your own voice, not to invent a different reason or re-derive the
        adherence math yourself.

        A STEADY week (reason "steady", deload false) needs no drama. A plain, short line is a
        complete answer; it doesn't need to manufacture tension where there isn't any. A DELOAD week
        (deload true) should say plainly that this week is lighter and roughly why (an honest miss
        last week, high strain, whatever get_week_adaptation names). A quality_delta that moved
        (nonzero) is worth a mention only if it's the actual headline of the week. Don't stack every
        fact into one sentence.

        Examples:
        - "steady week ahead, same shape as last."
        - "lighter week. last week only landed 62%, so this one backs off before it compounds."
        - "deload. the last stretch ran hot, this is the week that lets it cool."

        ANTI-PATTERN:
        - Restating adherence_pct as a bare number with no context ("62% adherence this week"): say
          what it means, not the raw figure.
        - Scolding. A missed week gets acknowledged, not lectured.
        - Praising a steady week just for being steady: showing up as planned isn't yet the kind of
          thing that earns praise.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(PlanAdaptation $adaptation): string
    {
        $decoded = $this->caller->call(
            kind: 'plan_week_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [],
            schemaName: 'TemariPlanWeekVoice',
            requiredKeys: ['voice'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $adaptation->user_id,
                maxTokens: 400,
                toolbox: new AgentToolbox([new PlanWeekTool($adaptation)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['voice'];
    }
}
