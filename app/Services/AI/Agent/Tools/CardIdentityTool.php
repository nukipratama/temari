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
        return 'Kartu yang lagi kamu tulis flavour-nya: rarity (pakai rarity_label kalau menyebutnya '
            .'dalam kalimat), special move, dan badge-nya. Mulai dari sini.';
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
