<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\FeaturedCardTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\RelativeEffort;

/**
 * Generates the mascot voice for the Featured Kartu hero panel on Today.
 * Split from {@see BriefingMascotVoiceNarrator} so the two surfaces can be
 * triggered and re-triggered independently without sharing LLM cost.
 */
class BriefingFeaturedKartuVoiceNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: 2-3 sentences, max 65 words, using "I" as the subject. Say why THIS
        run got a card, out of every run the user has done.

        The card isn't handed to you up front: call get_featured_card first, then
        write. Reference `name`, `rarity_label`, `km`, or `tags` when relevant, and
        never make up a detail that isn't there.

        WHY IT STOOD OUT: get_effort_context (when it's offered) has this session's
        load against the last 28 days' habits, plus its intent and decoupling. That
        is the answer to "why this one". A card earned by a session that cost
        noticeably more than the user's normal week is a different sentence from a
        card earned by a quiet one, and naming which it was beats naming the badge.
        If the tool isn't offered, or a field is missing, write from the card alone
        and don't mention that anything was unavailable.

        VARIATION:
        - An observation about `name`, the card's special move name: why that name
          fits this session.
        - Mention a badge from `tags`, or a specific `km`.
        - Tie `rarity_label` to what the session actually cost (its load against the
          user's normal, a long distance, a pace they held).

        Good examples, for register only, don't reuse them:
        - "12 km, and it cost you about a third more than your usual session. that's
          why this one got the card and the other four this month didn't."
        - "'Silent Steps' is a fair name for it. easy intent, easy execution, and
          your heart rate never once argued with your pace."

        ANTI-PATTERN:
        - "This card holds a memorable running story." -- too generic.
        - "Good morning..." / "Today..." -- generic greetings, not allowed.
        - Hyping the card instead of explaining it: "what an incredible run!",
          "amazing card!", "you crushed it". The reason it stood out is the whole
          content of this block.
        - Exclamation points. The card art is already doing the shouting.
        - Emoji.
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly RelativeEffort $relativeEffort,
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
            return 'no card this week yet. nothing has earned one so far.';
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
                toolbox: $this->toolbox($card),
                maxSteps: 6,
            ),
        );

        return (string) $decoded['kartu_voice'];
    }

    /**
     * The card itself, plus why this run stood out when the run behind it still
     * has its detail row — a card whose activity was never detailed simply has
     * fewer reads, rather than a tool that answers null to everything.
     */
    public function toolbox(RunCard $card): AgentToolbox
    {
        $card->loadMissing('activity.detail');
        $activity = $card->activity;
        $detail = $activity->detail;

        if ($detail === null) {
            return new AgentToolbox([new FeaturedCardTool($card)]);
        }

        return new AgentToolbox([
            new FeaturedCardTool($card),
            new EffortContextTool($activity, $detail, $this->relativeEffort),
        ]);
    }
}
