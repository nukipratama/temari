<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\RunCard;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Agent\Tools\CardIdentityTool;
use App\Services\AI\Agent\Tools\EffortContextTool;
use App\Services\AI\Agent\Tools\KmSplitsTool;
use App\Services\AI\Agent\Tools\RunSummaryTool;
use App\Services\AI\Agent\Tools\WeatherTool;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\RelativeEffort;

class CardFlavorNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Task: give 1 flavor sentence, max 30 words, for an activity card. Every card
        has a rarity (Common, Uncommon, Rare, Epic, Legendary) + a special move +
        badges. When mentioning rarity in the sentence, use those exact English
        labels.

        DATA: the card isn't handed to you up front. Fetch it yourself through the
        available tools, starting with get_card_identity -- call what you need, and
        if what comes back suggests another call would give a materially better
        answer, make it before writing the flavor line. Don't front-load every tool
        into one turn out of habit; read results as you go. NEVER make up a number
        you never fetched. If the run behind this card has no detailed data, the
        tool just isn't available: write from the card alone.

        Weave the badge combination, pacing, and weather into 1 sentence that shows
        why this card is special. Name the special move if it's distinctive, name a
        specific badge if there is one, mention the weather if it was extreme
        ("33-degree heat" or "rain").

        BUT: badge names and special move names are labels, not a story. Don't just
        string them together with connectors. State what earned that label, the
        number or the moment behind it, so the name actually means something.
        Wrong: "got the Z2 Master badge, carried by the Calm & Steady special move."
        That's two names glued together, no substance.
        Right: "you held Z2 for 90% of the run, seriously patient, no wonder the
        move is 'Calm & Steady'."

        WIND: only mention wind if it was strong or gusty (weather_wind_speed_kmh or
        weather_wind_gust_kmh is high) AND it actually played a role, e.g. a
        headwind that made a negative split more impressive. Wind isn't a mandatory
        detail on every card, skip it if it was calm.

        RAIN: check weather_rain_source. "observed" is fine to state plainly ("ran
        through rain"). "forecast" is just a prediction, so hedge ("might've caught
        some drizzle"), don't claim "heavy rain".

        PACING: negative_split true = the second half was faster, fine to praise.
        Low decoupling_pct = good aerobic efficiency. But if these two fields aren't
        present (no stream data), NEVER make any claim about pacing or negative
        splits at all, focus on the badge, weather, or special move instead.

        ANTI-PATTERN:
        - A generic sentence that could apply to any card.
        - Repeating the same formula for the same rarity.

        Good examples:
        - "'Silent Steps' earned the Rare label for a negative split in the second
          half, pace actually picked up in the pouring rain."
        - "Common card, but the special move 'New Morning' and the 8-degree cold
          make this session worth logging."
        PROMPT;

    public function __construct(
        private readonly StructuredChatCaller $caller,
        private readonly RelativeEffort $relativeEffort,
    ) {
    }

    public function generate(RunCard $card): string
    {
        $card->loadMissing('activity.detail');

        $decoded = $this->caller->call(
            kind: 'card_flavor',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [],
            schemaName: 'TemariCardFlavor',
            requiredKeys: ['flavor'],
            options: new ChatCallOptions(
                temperature: 0.8,
                userId: $card->activity->user_id,
                maxTokens: 400,
                toolbox: $this->toolbox($card),
            ),
        );

        return (string) $decoded['flavor'];
    }

    /**
     * The card's own identity, plus the run behind it when that run still has
     * its detail row — a card whose activity was never detailed simply has
     * fewer reads, rather than tools that answer null to everything.
     */
    public function toolbox(RunCard $card): AgentToolbox
    {
        $activity = $card->activity;
        $detail = $activity->detail;

        if ($detail === null) {
            return new AgentToolbox([new CardIdentityTool($card)]);
        }

        return new AgentToolbox([
            new CardIdentityTool($card),
            new RunSummaryTool($activity, $detail),
            new KmSplitsTool($activity, $detail),
            new WeatherTool($activity, $detail),
            new EffortContextTool($activity, $detail, $this->relativeEffort),
        ]);
    }
}
