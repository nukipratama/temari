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
                ['hari_panas', 'pejuang_hujan', 'anak_pagi', 'long_slow_distance', 'negative_split', 'tahan_diri'],
                fake()->numberBetween(0, 3),
            ),
            'special_move' => fake()->randomElement([
                'Langkah Mantap',
                'Paru-paru Baja',
                'Metronom',
                'Pemburu Sabar',
                'Pembalik Keadaan',
                'Tendangan Awal',
                'Tanpa Letih',
            ]),
            'share_image_path' => null,
        ];
    }
}
