<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PlannedSession;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\PlanDayTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TrainingBaseline;

class PlanDayVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: one short line about a single day of training, max 25 words. Before the day is run
        that is its prescribed session; after it, how the session actually went.

        DATA: call get_day_plan to see the day's session type, phase, and approximate distance
        before writing. Don't guess at what the day is.

        THE SESSION IS THE FACT. Say what kind of day it is and, when it helps, roughly how far.
        Never invent a number get_day_plan didn't give you. A rest day is just rest, say so plainly
        (fine to use the 🛌 glyph here, and only here). A skipped day (skipped is true) has already
        been excused by the athlete: acknowledge that, don't describe the original session as if it
        were still happening.

        ONCE THE DAY HAS BEEN RUN, YOU ARE READING IT, NOT ANNOUNCING IT. When get_day_plan comes
        back with a `status`, the session already happened and this line is a coach's read on how it
        went, in the past tense. Lead with what they did against what was asked, using distance_km
        (asked) and completed_km (run). `overreached` means they went well past the ask, `partial`
        means they came up short, `done` means they hit it, and `ran_anyway` true means they ran a
        day they had already excused themselves from, which is worth a nod. NEVER quote a percentage
        or a score out of 100, and never grade the athlete: name the two numbers and what they add
        up to. Without a `status` the day is still ahead of them, so write it as the label it was.
        Examples with a status:
        - "asked for 5.9, you ran 6 at tempo pace. that's the session, done properly."
        - "10 against an easy 7. more than the day wanted, but you clearly had it."
        - "3 of the 8 you were down for. short, but it's on the board."
        - "excused, and you ran it anyway. 6k."

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
        private readonly SessionMatcher $sessionMatcher,
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
                toolbox: new AgentToolbox([new PlanDayTool($session, $this->baseline, $this->completedKm($session))]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['voice'];
    }

    /**
     * Km run on the day, and only once it is graded: an ungraded day has no
     * outcome to read, so the query is skipped rather than answered with a
     * figure the tool then withholds.
     *
     * Delegates the day-total-vs-longest-run rule to {@see SessionMatcher},
     * which is where the score itself gets it. Re-deriving it here would put
     * the same rule in two places and let the narration quote a figure the
     * verdict was never computed from.
     */
    private function completedKm(PlannedSession $session): ?float
    {
        return $session->status->isCredited()
            ? $this->sessionMatcher->creditedKmFor($session)
            : null;
    }
}
