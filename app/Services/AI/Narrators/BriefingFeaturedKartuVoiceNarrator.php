<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\FeaturedCardTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;

/**
 * Generates the mascot voice for the Featured Kartu hero panel on HariIni.
 * Split from {@see BriefingMascotVoiceNarrator} so the two surfaces can be
 * triggered and re-triggered independently without sharing LLM cost.
 */
class BriefingFeaturedKartuVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 2-3 sentences in Temari's (mascot) voice, using "I" as the subject.
        Comment on the card given to the user, could be about the card's name, its
        rarity, or why that run earned a card. Tone: enthusiastic but still warm,
        not over the top. Max 65 words.

        The card isn't handed to you up front: call get_featured_card first, then
        write. Reference `name`, `rarity_label`, `km`, or `tags` when relevant, and
        never make up a detail that isn't there.

        VARIATION:
        - An observation about `name`, the card's special move name: why that name
          fits this session.
        - Mention a badge from `tags`, or a specific `km`.
        - Tie `rarity_label` to the session's effort (e.g. a long distance or a
          steady pace).

        Good example: "I gave you this card because that 12 km was genuinely solid.
        'Silent Steps' fits how steady your pace stayed from start to finish."

        ANTI-PATTERN:
        - "This card holds a memorable running story." -- too generic.
        - "Good morning..." / "Today..." -- generic greetings, not allowed.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
    ) {
    }

    /**
     * @param  RunCard|null  $card  The featured card resolved by
     *                              {@see \App\Actions\Run\Story\ResolveFeaturedKartuAction}. Its
     *                              `activity.detail` is not preloaded, so
     *                              {@see \App\Services\AI\Agent\Tools\FeaturedCardTool} lazy-loads
     *                              it for the km.
     */
    public function generate(User $user, ?RunCard $card): string
    {
        if ($card === null) {
            return "No special card for you this week yet. Keep running, I'm watching!";
        }

        $decoded = $this->caller->call(
            kind: 'briefing_featured_kartu_voice',
            systemPrompt: self::SYSTEM_PROMPT,
            context: ['name' => $user->firstName()],
            schemaName: 'TemariKartuVoice',
            requiredKeys: ['kartu_voice'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $user->id,
                maxTokens: 500,
                toolbox: new AgentToolbox([new FeaturedCardTool($card)]),
                maxSteps: 4,
            ),
        );

        return (string) $decoded['kartu_voice'];
    }
}
