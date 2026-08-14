<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StoryLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property int|null $activity_id
 * @property Carbon|null $for_date
 * @property string $mood
 * @property string $speech
 * @property string $sigil_pattern
 * @property-read User $user
 * @property-read Activity|null $activity
 */
#[Fillable([
    'user_id',
    'kind',
    'activity_id',
    'for_date',
    'mood',
    'speech',
    'sigil_pattern',
])]
class StoryLine extends Model
{
    /** @use HasFactory<StoryLineFactory> */
    use HasFactory;

    public const KIND_POST_RUN = 'post_run';

    public const KIND_DAILY_GREETING = 'daily_greeting';

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
            'user_id' => 'integer',
            'activity_id' => 'integer',
            'for_date' => 'date:Y-m-d',
        ];
    }
}
