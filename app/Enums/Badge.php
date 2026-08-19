<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Single source of truth for run-card badges: slug (backed value) and
 * human-facing label live in one place.
 */
enum Badge: string
{
    case HeatTamer = 'heat_tamer';
    case RainWarrior = 'rain_warrior';
    case EarlyBird = 'early_bird';
    case LongSlowDistance = 'long_slow_distance';
    case NegativeSplit = 'negative_split';
    case HeldBack = 'held_back';
    case NightOwl = 'night_owl';
    case Climber = 'climber';
    case FirstTimer = 'first_timer';
    case Speedster = 'speedster';
    case LongHauler = 'long_hauler';
    case Z2Master = 'z2_master';
    case ColdRunner = 'cold_runner';
    case AllOut = 'all_out';
    case EasyMiles = 'easy_miles';
    case Headwind = 'headwind';

    public function label(): string
    {
        return match ($this) {
            self::HeatTamer => '🔥 Heat Tamer',
            self::RainWarrior => '🌧️ Rain Warrior',
            self::EarlyBird => '🌅 Early Bird',
            self::LongSlowDistance => '🐢 Long Slow Distance',
            self::NegativeSplit => '👻 Negative Split',
            self::HeldBack => '🧘 Held Back',
            self::NightOwl => '🌙 Night Owl',
            self::Climber => '⛰️ Climber',
            self::FirstTimer => '🏅 First Timer',
            self::Speedster => '⚡ Speedster',
            self::LongHauler => '🗺️ Long Hauler',
            self::Z2Master => '🫀 Z2 Master',
            self::ColdRunner => '❄️ Cold Runner',
            self::AllOut => '😤 All Out',
            self::EasyMiles => '☺️ Easy Miles',
            self::Headwind => '🌬️ Headwind',
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
            self::NightOwl,
            self::EarlyBird,
            self::RainWarrior,
            self::NegativeSplit,
            self::HeatTamer,
            self::Z2Master,
            self::Headwind,
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
