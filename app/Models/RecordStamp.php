<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * The server-side "seen it" flag behind the run hero's PR bib stamp — one row
 * per (user, record) once that record's stamp has played, so opening the same
 * record run again, or on another device, shows the badge as a plain static
 * fact rather than replaying the animation.
 *
 * @property int $id
 * @property int $user_id
 * @property string $record_key
 * @property Carbon $seen_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'record_key',
    'seen_at',
])]
class RecordStamp extends Model
{
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
            'seen_at' => 'datetime',
        ];
    }
}
