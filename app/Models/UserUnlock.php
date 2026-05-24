<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserUnlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $unlock_key
 * @property Carbon $unlocked_at
 * @property array<string, mixed>|null $metadata
 * @property bool $equipped
 * @property-read User $user
 */
#[Fillable(['user_id', 'unlock_key', 'unlocked_at', 'metadata', 'equipped'])]
class UserUnlock extends Model
{
    /** @use HasFactory<UserUnlockFactory> */
    use HasFactory;

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
            'unlocked_at' => 'datetime',
            'metadata' => 'array',
            'equipped' => 'boolean',
        ];
    }
}
