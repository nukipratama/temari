<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rarity;
use Database\Factories\RunCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $activity_id
 * @property Rarity $rarity
 * @property array<int, string> $badges
 * @property string $special_move
 * @property string|null $share_image_path
 * @property-read Activity $activity
 */
#[Fillable([
    'activity_id',
    'rarity',
    'badges',
    'special_move',
    'share_image_path',
])]
class RunCard extends Model
{
    /** @use HasFactory<RunCardFactory> */
    use HasFactory;

    public const string BADGE_HARI_PANAS = 'hari_panas';

    public const string BADGE_PEJUANG_HUJAN = 'pejuang_hujan';

    public const string BADGE_ANAK_PAGI = 'anak_pagi';

    public const string BADGE_LONG_SLOW_DISTANCE = 'long_slow_distance';

    public const string BADGE_NEGATIVE_SPLIT = 'negative_split';

    public const string BADGE_TAHAN_DIRI = 'tahan_diri';

    public const string BADGE_ANAK_MALAM = 'anak_malam';

    public const string BADGE_PENDAKI = 'pendaki';

    public const string BADGE_PERTAMA_KALI = 'pertama_kali';

    public const string BADGE_RAJIN = 'rajin';

    public const string BADGE_KILAT = 'kilat';

    public const string BADGE_JAUH = 'jauh';

    public const string BADGE_Z2_MASTER = 'z2_master';

    public const string BADGE_ANAK_DINGIN = 'anak_dingin';

    public const string BADGE_KERAS = 'keras';

    public const string BADGE_SANTAI = 'santai';

    public const string BADGE_BERTURUT = 'berturut';

    public const string BADGE_HARI_SPESIAL = 'hari_spesial';

    public const array BADGE_LABELS = [
        self::BADGE_HARI_PANAS => '🔥 Tahan Gerah',
        self::BADGE_PEJUANG_HUJAN => '🌧️ Pejuang Hujan',
        self::BADGE_ANAK_PAGI => '🌅 Anak Pagi',
        self::BADGE_LONG_SLOW_DISTANCE => '🐢 Long Slow Distance',
        self::BADGE_NEGATIVE_SPLIT => '👻 Negative Split',
        self::BADGE_TAHAN_DIRI => '🧘 Anti Kalap',
        self::BADGE_ANAK_MALAM => '🌙 Anak Malam',
        self::BADGE_PENDAKI => '⛰️ Pendaki',
        self::BADGE_PERTAMA_KALI => '🏅 Pertama Kali',
        self::BADGE_RAJIN => '💪 Rajin',
        self::BADGE_KILAT => '⚡ Kilat',
        self::BADGE_JAUH => '🗺️ Jauh',
        self::BADGE_Z2_MASTER => '🫀 Z2 Master',
        self::BADGE_ANAK_DINGIN => '❄️ Anak Dingin',
        self::BADGE_KERAS => '😤 Keras',
        self::BADGE_SANTAI => '☺️ Santai',
        self::BADGE_BERTURUT => '🔥 Berturut',
        self::BADGE_HARI_SPESIAL => '🎉 Hari Spesial',
    ];

    /**
     * Badges tracked by the gamification unlock criteria.
     *
     * @var list<string>
     */
    private const array TRACKED_BADGES = [
        self::BADGE_ANAK_MALAM,
        self::BADGE_ANAK_PAGI,
        self::BADGE_PEJUANG_HUJAN,
        self::BADGE_NEGATIVE_SPLIT,
        self::BADGE_HARI_PANAS,
        self::BADGE_Z2_MASTER,
    ];

    /**
     * Count how many of this user's cards carry each tracked badge.
     * Single query, counts in PHP to avoid N per-badge round-trips.
     *
     * @return array<string, int>
     */
    public static function badgeCountsForUser(int $userId): array
    {
        $counts = array_fill_keys(self::TRACKED_BADGES, 0);

        $allBadges = self::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $userId))
            ->pluck('badges');

        foreach ($allBadges as $cardBadges) {
            foreach ($cardBadges ?? [] as $badge) {
                if (isset($counts[$badge])) {
                    $counts[$badge]++;
                }
            }
        }

        return $counts;
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'badges' => 'array',
            'rarity' => Rarity::class,
        ];
    }
}
