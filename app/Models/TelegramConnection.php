<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SharedPropCacheKey;
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
 * @property Carbon|null $revoked_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'chat_id',
    'username',
    'revoked_at',
])]
class TelegramConnection extends Model
{
    /** @use HasFactory<TelegramConnectionFactory> */
    use HasFactory;

    /**
     * Keep the shared `telegramConnected` Inertia prop in step with the link
     * state. Covers every writer, since the connect upsert and `markRevoked()`
     * both go through the model. The one query-builder write that bypasses this
     * ({@see \App\Jobs\Telegram\HandleTelegramUpdateJob}, clearing another
     * user's already-revoked row so its chat_id can be reused) cannot change the
     * prop: that user was unreachable before the delete and stays unreachable
     * after it.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saved(function (TelegramConnection $connection): void {
            SharedPropCacheKey::TelegramConnected->forget($connection->user_id);
        });
    }

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
            'user_id' => 'integer',
            'chat_id' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }
}
