<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\RunCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunCard>
 */
class RunCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'rarity' => fake()->randomElement(Rarity::cases()),
            'badges' => fake()->randomElements(
                ['heat_tamer', 'rain_warrior', 'early_bird', 'long_slow_distance', 'negative_split', 'held_back'],
                fake()->numberBetween(0, 3),
            ),
            'special_move' => fake()->randomElement([
                'Steady Tempo',
                'Paru-paru Baja',
                'Metronom',
                'Pemburu Sabar',
                'Pembalik Keadaan',
                'Tendangan Awal',
                'Tanpa Letih',
            ]),
            'pr_set' => false,
            'share_image_path' => null,
        ];
    }
}
