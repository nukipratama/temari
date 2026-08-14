<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * One row per (analysis, channel): both the idempotency claim taken before a
 * send and the outcome recorded after it. Written through
 * {@see \App\Services\Notifications\NotificationDeliveryClaim}.
 *
 * @property int $id
 * @property int $analysis_id
 * @property string $channel
 * @property NotificationDeliveryStatus $status
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $settled_at
 */
#[Fillable([
    'analysis_id',
    'channel',
    'status',
    'created_at',
    'error',
    'settled_at',
])]
class NotificationDelivery extends Model
{
    #[Override]
    public $timestamps = false;

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'analysis_id' => 'integer',
            'status' => NotificationDeliveryStatus::class,
            'created_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
