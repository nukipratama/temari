<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationKind;
use App\Notifications\Messages\InboxMessage;
use App\Support\SharedPropCacheKey;
use Database\Factories\InboxNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * A row in the user's notification inbox: the durable record of everything
 * Temari sent them, kept whether or not any outbound channel was wired.
 *
 * Named for what it is rather than after its `notifications` table, which the
 * framework's own {@see \Illuminate\Notifications\DatabaseNotification} would
 * otherwise claim.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationKind $kind
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $title
 * @property string|null $body
 * @property array<string, mixed>|null $payload
 * @property string $dedupe_key
 * @property Carbon|null $read_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'kind',
    'subject_type',
    'subject_id',
    'title',
    'body',
    'payload',
    'dedupe_key',
    'read_at',
])]
class InboxNotification extends Model
{
    /** @use HasFactory<InboxNotificationFactory> */
    use HasFactory;

    #[Override]
    protected $table = 'notifications';

    /**
     * Writes the row for a delivered {@see InboxMessage}, returning false when
     * the (user, dedupe key) pair already exists. insertOrIgnore is atomic on
     * that unique pair, so a queued retry racing its own first attempt adds
     * nothing rather than a second row.
     */
    public static function record(User $user, InboxMessage $message, string $dedupeKey): bool
    {
        $now = Carbon::now();

        $inserted = self::query()->insertOrIgnore([
            'user_id' => $user->id,
            'kind' => $message->kind->value,
            'subject_type' => $message->subjectType,
            'subject_id' => $message->subjectId,
            'title' => $message->title,
            'body' => $message->body,
            'payload' => $message->payload === [] ? null : json_encode($message->payload),
            'dedupe_key' => $dedupeKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]) !== 0;

        if ($inserted) {
            SharedPropCacheKey::UnreadNotifications->forget($user->id);
        }

        return $inserted;
    }

    public static function unreadCountFor(int $userId): int
    {
        return self::query()->where('user_id', $userId)->unread()->count();
    }

    public function markRead(): void
    {
        if ($this->read_at !== null) {
            return;
        }

        $this->read_at = Carbon::now();
        $this->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<InboxNotification>  $query
     */
    #[Scope]
    protected function unread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * The unread count is a shared prop, so both the write path (via
     * {@see self::record()}, which bypasses model events) and the read path have
     * to bust it.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saved(function (InboxNotification $notification): void {
            SharedPropCacheKey::UnreadNotifications->forget($notification->user_id);
        });

        static::deleted(function (InboxNotification $notification): void {
            SharedPropCacheKey::UnreadNotifications->forget($notification->user_id);
        });
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'subject_id' => 'integer',
            'kind' => NotificationKind::class,
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
