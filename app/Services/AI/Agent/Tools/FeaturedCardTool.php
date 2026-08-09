<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\Badge;
use App\Models\RunCard;
use App\Services\Run\Metrics\DistanceFormatter;

/**
 * The card the briefing is featuring this week.
 */
final class FeaturedCardTool extends NoArgumentTool
{
    private const int MAX_TAGS = 3;

    public function __construct(private readonly RunCard $card)
    {
    }

    public function name(): string
    {
        return 'get_featured_card';
    }

    public function description(): string
    {
        return "This week's featured card: its special move name, rarity_label, run distance, and "
            .'up to three top badges. Start here.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $distance = $this->card->activity->detail?->distance;

        return [
            'name' => $this->card->special_move,
            'rarity_label' => $this->card->rarity->label(),
            'km' => $distance !== null ? DistanceFormatter::km($distance).'km' : '-',
            'tags' => array_slice(Badge::promptLabelsFor((array) ($this->card->badges ?? [])), 0, self::MAX_TAGS),
        ];
    }
}
