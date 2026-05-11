<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RunCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $activity_id
 * @property string $rarity
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

    public const string RARITY_BIASA = 'biasa';

    public const string RARITY_JARANG = 'jarang';

    public const string RARITY_LANGKA = 'langka';

    public const string RARITY_EPIK = 'epik';

    public const string RARITY_LEGENDARIS = 'legendaris';

    /** Display labels for the rarity ladder (Indonesian). */
    public const array RARITY_LABELS = [
        self::RARITY_BIASA => 'Biasa',
        self::RARITY_JARANG => 'Jarang',
        self::RARITY_LANGKA => 'Langka',
        self::RARITY_EPIK => 'Epik',
        self::RARITY_LEGENDARIS => 'Legendaris',
    ];

    public const string BADGE_HARI_PANAS = 'hari_panas';

    public const string BADGE_PEJUANG_HUJAN = 'pejuang_hujan';

    public const string BADGE_ANAK_PAGI = 'anak_pagi';

    public const string BADGE_LONG_SLOW_DISTANCE = 'long_slow_distance';

    public const string BADGE_NEGATIVE_SPLIT = 'negative_split';

    public const string BADGE_TAHAN_DIRI = 'tahan_diri';

    /** Display labels (with emoji) for each badge key. */
    public const array BADGE_LABELS = [
        self::BADGE_HARI_PANAS => '🔥 Hari Panas',
        self::BADGE_PEJUANG_HUJAN => '🌧️ Pejuang Hujan',
        self::BADGE_ANAK_PAGI => '🌅 Anak Pagi',
        self::BADGE_LONG_SLOW_DISTANCE => '🐢 Long Slow Distance',
        self::BADGE_NEGATIVE_SPLIT => '👻 Negative Split',
        self::BADGE_TAHAN_DIRI => '🧘 Tahan Diri',
    ];

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
        return ['badges' => 'array'];
    }
}
