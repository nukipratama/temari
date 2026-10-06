<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * One channel's send of a notification triggered inside quiet hours, written by
 * {@see \App\Listeners\HoldNotificationsInQuietHours} and replayed in id order by
 * {@see \App\Console\Commands\Notifications\ReleaseHeldNotificationsCommand}.
 *
 * @property int $id
 * @property int $user_id
 * @property class-string $channel
 * @property string|null $dedupe_key
 * @property string $notification
 * @property Carbon $held_at
 */
#[Fillable(['user_id', 'channel', 'dedupe_key', 'notification', 'held_at'])]
#[WithoutTimestamps]
class HeldNotification extends Model
{
    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'held_at' => 'datetime',
        ];
    }
}
