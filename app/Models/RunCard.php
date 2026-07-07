<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Badge;
use App\Enums\Rarity;
use Database\Factories\RunCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Count how many of this user's cards carry each tracked badge.
     * Single query, counts in PHP to avoid N per-badge round-trips.
     *
     * @return array<string, int>
     */
    public static function badgeCountsForUser(int $userId): array
    {
        $tracked = Badge::tracked();
        $trackedValues = array_map(fn (Badge $b): string => $b->value, $tracked);
        $counts = array_fill_keys($trackedValues, 0);

        $rows = self::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $userId))
            ->select('badges')
            ->lazy();

        foreach ($rows as $row) {
            foreach ($row->badges ?? [] as $badge) {
                if (isset($counts[$badge])) {
                    $counts[$badge]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Cards owned by the given user (i.e. whose source activity belongs to them).
     *
     * @param  Builder<RunCard>  $query
     * @return Builder<RunCard>
     */
    #[Scope]
    protected function forUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('activity', fn ($q) => $q->where('user_id', $userId));
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
