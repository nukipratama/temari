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

    public const array BADGE_LABELS = [
        self::BADGE_HARI_PANAS => '🔥 Tahan Gerah',
        self::BADGE_PEJUANG_HUJAN => '🌧️ Pejuang Hujan',
        self::BADGE_ANAK_PAGI => '🌅 Anak Pagi',
        self::BADGE_LONG_SLOW_DISTANCE => '🐢 Long Slow Distance',
        self::BADGE_NEGATIVE_SPLIT => '👻 Negative Split',
        self::BADGE_TAHAN_DIRI => '🧘 Anti Kalap',
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
        return [
            'badges' => 'array',
            'rarity' => Rarity::class,
        ];
    }
}
