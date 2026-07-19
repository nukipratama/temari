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
 * A user's per-type notification opt-ins, channel-neutral: the same toggles gate
 * both Telegram and web push. A missing row means all-on (the default), so a user
 * who never touched the settings is opted into everything.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $post_run
 * @property bool $weekly_recap
 * @property bool $monthly_recap
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'post_run',
    'weekly_recap',
    'monthly_recap',
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
        ];
    }
}
