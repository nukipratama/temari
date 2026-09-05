<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PlannedSession;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanDayTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Plan\TrainingBaseline;

class PlanDayVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: one short line narrating a single day's prescribed training session, max 25 words.

        DATA: call get_day_plan to see the day's session type, phase, and approximate distance
        before writing. Don't guess at what the day is.

        THE SESSION IS THE FACT. Say what kind of day it is and, when it helps, roughly how far.
        Never invent a number get_day_plan didn't give you. A rest day is just rest, say so plainly
        (fine to use the 🛌 glyph here, and only here). A skipped day (skipped is true) has already
        been excused by the athlete: acknowledge that, don't describe the original session as if it
        were still happening.

        Feel free to gesture at how the day sits in the week (e.g. a long run the week is built
        around, a tempo day after a rest) when the phase or type makes that obvious, but don't force
        a narrative connection that isn't there. A day with nothing notable about it just gets stated.

        Examples:
        - "long run today, around 16k. this is the one the week's built around."
        - "tempo work. legs should still be fresh off yesterday's rest."
        - "easy day. nothing to prove, just log the miles."
        - "rest. 🛌"
        - "skipped. next one's still on the schedule."

        ANTI-PATTERN:
        - Quoting a distance you didn't get from get_day_plan.
        - Explaining why the athlete should or shouldn't run today: that's a training-disclaimer
          concern, not narration.
        - A pep talk. This is a one-line label, not a motivational speech.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly TrainingBaseline $baseline,
    ) {
    }

    public function generate(PlannedSession $session): string
    {
        $decoded = $this->caller->call(
            kind: 'plan_day_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [],
            schemaName: 'TemariPlanDayVoice',
            requiredKeys: ['voice'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $session->user_id,
                maxTokens: 300,
                toolbox: new AgentToolbox([new PlanDayTool($session, $this->baseline)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['voice'];
    }
}
