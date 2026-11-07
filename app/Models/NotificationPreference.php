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
 * **What** gets sent — `notifications_enabled` — is one channel-neutral master
 * switch over everything Temari initiates: the post-run story, the weekly and
 * monthly recaps, the morning briefing push, the fitness-improved word, the
 * time-trial check, the hot-easy-run warning, the plan-change word, the race-day-tomorrow reminder, the race outcome check-in and
 * the streak-at-risk nudge. The same switch gates Telegram and web push alike.
 *
 * The morning briefing push also has its own switch, `briefing_push_enabled`,
 * under the master one, and an optional send time, `briefing_push_minute`, in
 * minutes since local midnight; null sends it at the automatic time.
 *
 * **Where** it may go — `telegram_enabled`, `push_enabled` — is a non-destructive
 * mute: off means the connection or subscription stays intact and simply
 * receives nothing. The two axes are independent.
 *
 * A missing row means all-on for both axes, so a user who never opened the
 * settings receives everything on every wired channel.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $notifications_enabled
 * @property bool $briefing_push_enabled
 * @property int|null $briefing_push_minute
 * @property bool $telegram_enabled
 * @property bool $push_enabled
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'notifications_enabled',
    'briefing_push_enabled',
    'briefing_push_minute',
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
            'user_id' => 'integer',
            'notifications_enabled' => 'boolean',
            'briefing_push_enabled' => 'boolean',
            'briefing_push_minute' => 'integer',
            'telegram_enabled' => 'boolean',
            'push_enabled' => 'boolean',
        ];
    }
}
