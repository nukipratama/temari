<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecoveryConcernLevel;
use App\Enums\SleepQuality;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property SleepQuality|null $sleep_quality
 * @property RecoveryConcernLevel|null $fatigue
 * @property RecoveryConcernLevel|null $soreness
 * @property bool|null $concerning_pain
 * @property bool|null $illness
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'date',
    'sleep_quality',
    'fatigue',
    'soreness',
    'concerning_pain',
    'illness',
])]
class RecoveryFeedback extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'date' => 'date:Y-m-d',
            'sleep_quality' => SleepQuality::class,
            'fatigue' => RecoveryConcernLevel::class,
            'soreness' => RecoveryConcernLevel::class,
            'concerning_pain' => 'boolean',
            'illness' => 'boolean',
        ];
    }
}
