<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A user's notification preferences, on two independent axes.
 *
 * **What** gets sent — `post_run`, `weekly_recap`, `monthly_recap` — stays
 * channel-neutral: the same toggle gates Telegram and web push alike.
 *
 * **Where** it may go — `telegram_enabled`, `push_enabled` — is a non-destructive
 * mute. Off means the connection or subscription stays intact and simply
 * receives nothing, which is the whole point: the alternative was revoking the
 * Telegram link or dropping the push subscription, both expensive to undo (push
 * needs a fresh browser permission grant, unrecoverable on iOS once denied).
 *
 * Keeping the axes independent is deliberate. Crossing them would give a 3x2
 * matrix of toggles, which is more control than anyone wants to configure.
 *
 * A missing row means all-on for both axes, so a user who never opened the
 * settings receives everything on every wired channel.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $post_run
 * @property bool $weekly_recap
 * @property bool $monthly_recap
 * @property bool $telegram_enabled
 * @property bool $push_enabled
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'post_run',
    'weekly_recap',
    'monthly_recap',
    'telegram_enabled',
    'push_enabled',
])]
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'post_run' => 'boolean',
            'weekly_recap' => 'boolean',
            'monthly_recap' => 'boolean',
            'telegram_enabled' => 'boolean',
            'push_enabled' => 'boolean',
        ];
    }
}
