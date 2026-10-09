<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property bool $pr_set
 * @property-read Activity $activity
 */
#[Fillable([
    'activity_id',
    'rarity',
    'badges',
    'special_move',
    'pr_set',
])]
class RunCard extends Model
{
    /** @use HasFactory<RunCardFactory> */
    use HasFactory;

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
            'activity_id' => 'integer',
            'badges' => 'array',
            'rarity' => Rarity::class,
            'pr_set' => 'boolean',
        ];
    }
}
