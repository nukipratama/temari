<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Enums\SessionType;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\ReadinessCeiling;

/**
 * One line naming today's eased session as the day's session, and why it was eased.
 *
 * Deliberately its own narrator rather than a branch of
 * {@see PlanDayVoiceNarrator}: the day's blurb is fingerprinted on the stored
 * session and must not re-bill every time readiness moves, which on a clamped
 * day is exactly what happens. This one carries no tools — everything it needs
 * is three coarse facts, and the numbers on the card are already right beside
 * it. Before the day is credited this line is the eased day's voice; see
 * `docs/decisions/the-eased-session-leads.md`.
 */
class PlanClampVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: one short line, max 25 words: today's session, and why it is the eased one.

        You are given: planned, the session the plan originally asked for; stepped_down_to, the
        session readiness eased it to; and readiness_reasons, the exact facts that justify the change.
        Treat concerning_pain_reported as reported pain concern, illness_reported as reported illness,
        demanding_session_within_24h as a demanding session in the last day, and
        closely_spaced_demanding_sessions as multiple hard sessions close together. Use only facts
        in readiness_reasons. There are no tools and no other numbers: the distance
        and pace are already on the card next to this line, so quoting one adds nothing and inventing
        one is worse.

        stepped_down_to is the session the athlete is running today. Name it as today's session, as
        a plain fact, and mention planned only as what it replaced. Then give the reason using only
        readiness_reasons. Never infer fatigue from the ceiling or time since the last run. When
        stepped_down_to is rest, today is a rest day.

        Steady and matter-of-fact. An eased day is a normal part of training, not a failure and not
        a scolding. Never imply they did something wrong, and never tell them to see a doctor or
        rest more than the plan says: the card is the prescription, this is the explanation.

        Examples:
        - "you reported concerning pain, so today's a rest day instead of the intervals."
        - "you completed a demanding session within the last day, so today's easy and the tempo can wait."

        ANTI-PATTERN:
        - Quoting a distance or a pace. You do not have them.
        - "Listen to your body" or any variation. Say the actual reason.
        - Describing the session that was replaced as if it were still happening.
        - Framing today as a step-down from a session they are still doing. The eased session is the day.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    /** @param array{ceiling: ReadinessCeiling, original: SessionType, clamped_to: SessionType, has_run_today: bool, readiness_reasons: list<string>, readiness_inputs: array<string, mixed>} $context */
    public function generate(array $context, int $userId): string
    {
        $decoded = $this->caller->call(
            kind: 'plan_clamp_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'planned' => $context['original']->value,
                'stepped_down_to' => $context['clamped_to']->value,
                'readiness_reasons' => $context['readiness_reasons'],
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
