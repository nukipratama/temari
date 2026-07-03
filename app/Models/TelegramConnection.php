<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TelegramConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property int $chat_id
 * @property string|null $username
 * @property bool $notify_post_run
 * @property bool $notify_weekly_recap
 * @property bool $notify_monthly_recap
 * @property Carbon|null $revoked_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'chat_id',
    'username',
    'notify_post_run',
    'notify_weekly_recap',
    'notify_monthly_recap',
    'revoked_at',
])]
class TelegramConnection extends Model
{
    /** @use HasFactory<TelegramConnectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<TelegramConnection>  $query
     * @return Builder<TelegramConnection>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function markRevoked(): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $this->update(['revoked_at' => Carbon::now()]);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'notify_post_run' => 'boolean',
            'notify_weekly_recap' => 'boolean',
            'notify_monthly_recap' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }
}
