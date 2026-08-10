<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Single source of truth for run-card badges: slug (backed value) and
 * human-facing label live in one place.
 */
enum Badge: string
{
    case HariPanas = 'heat_tamer';
    case PejuangHujan = 'rain_warrior';
    case AnakPagi = 'early_bird';
    case LongSlowDistance = 'long_slow_distance';
    case NegativeSplit = 'negative_split';
    case TahanDiri = 'held_back';
    case AnakMalam = 'night_owl';
    case Pendaki = 'climber';
    case PertamaKali = 'first_timer';
    case Rajin = 'habit_forming';
    case Kilat = 'speedster';
    case Jauh = 'long_hauler';
    case Z2Master = 'z2_master';
    case AnakDingin = 'cold_runner';
    case Keras = 'all_out';
    case Santai = 'easy_miles';
    case Berturut = 'streak';
    case HariSpesial = 'holiday_run';
    case LawanAngin = 'headwind';

    public function label(): string
    {
        return match ($this) {
            self::HariPanas => '🔥 Heat Tamer',
            self::PejuangHujan => '🌧️ Rain Warrior',
            self::AnakPagi => '🌅 Early Bird',
            self::LongSlowDistance => '🐢 Long Slow Distance',
            self::NegativeSplit => '👻 Negative Split',
            self::TahanDiri => '🧘 Held Back',
            self::AnakMalam => '🌙 Night Owl',
            self::Pendaki => '⛰️ Climber',
            self::PertamaKali => '🏅 First Timer',
            self::Rajin => '💪 Habit Forming',
            self::Kilat => '⚡ Speedster',
            self::Jauh => '🗺️ Long Hauler',
            self::Z2Master => '🫀 Z2 Master',
            self::AnakDingin => '❄️ Cold Runner',
            self::Keras => '😤 All Out',
            self::Santai => '☺️ Easy Miles',
            self::Berturut => '🔥 Streak',
            self::HariSpesial => '🎉 Holiday Run',
            self::LawanAngin => '🌬️ Headwind',
        };
    }

    /**
     * Badges tracked by the gamification unlock criteria.
     *
     * @return list<self>
     */
    public static function tracked(): array
    {
        return [
            self::AnakMalam,
            self::AnakPagi,
            self::PejuangHujan,
            self::NegativeSplit,
            self::HariPanas,
            self::Z2Master,
            self::LawanAngin,
        ];
    }

    /** @return array<string, string> slug → label for the full catalog */
    public static function labels(): array
    {
        return array_combine(
            array_map(fn (self $b): string => $b->value, self::cases()),
            array_map(fn (self $b): string => $b->label(), self::cases()),
        );
    }

    /**
     * Emoji-free label for LLM prompt context, so the model has a human phrase to
     * weave in instead of echoing the raw snake_case slug ("negative_split").
     */
    public function promptLabel(): string
    {
        return (string) preg_replace('/^[^\p{L}]+/u', '', $this->label());
    }

    /**
     * Map a list of badge slugs to their prompt labels, dropping any unknown slug.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    public static function promptLabelsFor(array $slugs): array
    {
        return array_values(array_filter(array_map(
            fn (string $slug): ?string => self::tryFrom($slug)?->promptLabel(),
            $slugs,
        )));
    }
}
