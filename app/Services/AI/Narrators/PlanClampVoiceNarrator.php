<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Enums\SessionType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\ReadinessCeiling;

/**
 * One line explaining why today stepped down from what the plan asked for.
 *
 * Deliberately its own narrator rather than a branch of
 * {@see PlanDayVoiceNarrator}: the day's blurb is fingerprinted on the stored
 * session and must not re-bill every time readiness moves, which on a clamped
 * day is exactly what happens. This one carries no tools — everything it needs
 * is three coarse facts, and the numbers on the card are already right beside
 * it. See `docs/decisions/readiness-clamp-is-advisory.md`.
 */
class PlanClampVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: one short line, max 25 words, explaining why today is easier than the plan asked for.

        You are given: what the plan asked for, what it stepped down to, how tired the athlete is
        (the ceiling), and whether they have already run today. That is everything. There are no
        tools and no other numbers — the distances are already on the card next to this line, so
        quoting one adds nothing and inventing one is worse.

        SAY WHY, NOT WHAT. The card already shows the step-down. This line is the reason for it:
        accumulated fatigue, a hard recent stretch, or a session already run today. If they have
        already run, that is almost always the reason and should lead.

        Steady and matter-of-fact. A step-down is a normal part of training, not a failure and not
        a scolding. Never imply they did something wrong, and never tell them to see a doctor or
        rest more than the plan says — the card is the prescription, this is the explanation.

        Examples:
        - "you already ran this morning, so the intervals give way to something easy."
        - "load's been stacking up all week. today gives it somewhere to go."
        - "you're carrying a lot right now, so the long run waits for fresher legs."
        - "nothing wrong, just a heavy stretch. easy today keeps the week intact."

        ANTI-PATTERN:
        - Quoting a distance or a pace. You do not have them.
        - "Listen to your body" or any variation. Say the actual reason.
        - Describing the session that was replaced as if it were still happening.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    /** @param array{ceiling: ReadinessCeiling, original: SessionType, clamped_to: SessionType, has_run_today: bool} $context */
    public function generate(array $context, int $userId): string
    {
        $decoded = $this->caller->call(
            kind: 'plan_clamp_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'planned' => $context['original']->value,
                'stepped_down_to' => $context['clamped_to']->value,
                'readiness_ceiling' => $context['ceiling']->value,
                'already_ran_today' => $context['has_run_today'],
            ],
            schemaName: 'TemariPlanClampVoice',
            requiredKeys: ['voice'],
            options: new ChatCallOptions(
                temperature: 0.7,
                userId: $userId,
                maxTokens: 200,
            ),
        );

        return (string) $decoded['voice'];
    }
}
