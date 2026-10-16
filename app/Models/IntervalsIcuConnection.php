<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntervalsIcuSyncStatus;
use Database\Factories\IntervalsIcuConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $athlete_id
 * @property string $api_key
 * @property Carbon $verified_at
 * @property Carbon|null $broken_at
 * @property Carbon|null $synced_on
 * @property IntervalsIcuSyncStatus|null $sync_status
 * @property string|null $event_id
 * @property string|null $payload_hash
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'athlete_id',
    'api_key',
    'verified_at',
    'broken_at',
    'synced_on',
    'sync_status',
    'event_id',
    'payload_hash',
])]
#[Hidden(['api_key'])]
class IntervalsIcuConnection extends Model
{
    /** @use HasFactory<IntervalsIcuConnectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBroken(): bool
    {
        return $this->broken_at !== null;
    }

    /** Where the workout for `$day` stands, null before the sync has looked at that day. */
    public function statusOn(Carbon $day): ?IntervalsIcuSyncStatus
    {
        return $this->synced_on?->isSameDay($day) === true ? $this->sync_status : null;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'api_key' => 'encrypted',
            'verified_at' => 'datetime',
            'broken_at' => 'datetime',
            'synced_on' => 'date',
            'sync_status' => IntervalsIcuSyncStatus::class,
        ];
    }
}
