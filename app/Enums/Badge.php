<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Single source of truth for run-card badges: slug (backed value) and
 * human-facing label live in one place.
 */
enum Badge: string
{
    case HariPanas = 'hari_panas';
    case PejuangHujan = 'pejuang_hujan';
    case AnakPagi = 'anak_pagi';
    case LongSlowDistance = 'long_slow_distance';
    case NegativeSplit = 'negative_split';
    case TahanDiri = 'tahan_diri';
    case AnakMalam = 'anak_malam';
    case Pendaki = 'pendaki';
    case PertamaKali = 'pertama_kali';
    case Rajin = 'rajin';
    case Kilat = 'kilat';
    case Jauh = 'jauh';
    case Z2Master = 'z2_master';
    case AnakDingin = 'anak_dingin';
    case Keras = 'keras';
    case Santai = 'santai';
    case Berturut = 'berturut';
    case HariSpesial = 'hari_spesial';
    case LawanAngin = 'lawan_angin';

    public function label(): string
    {
        return match ($this) {
            self::HariPanas => '🔥 Tahan Gerah',
            self::PejuangHujan => '🌧️ Pejuang Hujan',
            self::AnakPagi => '🌅 Anak Pagi',
            self::LongSlowDistance => '🐢 Long Slow Distance',
            self::NegativeSplit => '👻 Negative Split',
            self::TahanDiri => '🧘 Anti Kalap',
            self::AnakMalam => '🌙 Anak Malam',
            self::Pendaki => '⛰️ Pendaki',
            self::PertamaKali => '🏅 Pertama Kali',
            self::Rajin => '💪 Rajin',
            self::Kilat => '⚡ Kilat',
            self::Jauh => '🗺️ Jauh',
            self::Z2Master => '🫀 Z2 Master',
            self::AnakDingin => '❄️ Anak Dingin',
            self::Keras => '😤 Keras',
            self::Santai => '☺️ Santai',
            self::Berturut => '🔥 Berturut',
            self::HariSpesial => '🎉 Hari Spesial',
            self::LawanAngin => '🌬️ Lawan Angin',
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
