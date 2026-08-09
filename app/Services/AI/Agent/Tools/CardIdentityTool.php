<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\Badge;
use App\Models\RunCard;

/**
 * What the card *is* — the one read a card-flavor run cannot write without.
 */
final class CardIdentityTool extends NoArgumentTool
{
    public function __construct(private readonly RunCard $card)
    {
    }

    public function name(): string
    {
        return 'get_card_identity';
    }

    public function description(): string
    {
        return "The card whose flavor you're writing: rarity (use rarity_label if you mention it in "
            .'the sentence), special move, and its badges. Start here.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return [
            'rarity' => $this->card->rarity->value,
            'rarity_label' => $this->card->rarity->label(),
            'special_move' => $this->card->special_move,
            'badges' => Badge::promptLabelsFor((array) ($this->card->badges ?? [])),
        ];
    }
}
