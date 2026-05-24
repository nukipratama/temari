<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrCategory;
use Database\Factories\PersonalRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property PrCategory $category
 * @property float $value_sec
 * @property int|null $activity_id
 * @property Carbon $set_at
 * @property-read User $user
 * @property-read Activity|null $activity
 */
#[Fillable([
    'user_id',
    'category',
    'value_sec',
    'activity_id',
    'set_at',
])]
class PersonalRecord extends Model
{
    /** @use HasFactory<PersonalRecordFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'value_sec' => 'float',
            'set_at' => 'datetime',
            'category' => PrCategory::class,
        ];
    }
}
