<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntervalsIcuConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $athlete_id
 * @property string $api_key
 * @property Carbon $verified_at
 * @property Carbon|null $broken_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'athlete_id',
    'api_key',
    'verified_at',
    'broken_at',
])]
#[Hidden(['api_key'])]
class IntervalsIcuConnection extends Model
{
    /** @use HasFactory<IntervalsIcuConnectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBroken(): bool
    {
        return $this->broken_at !== null;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'api_key' => 'encrypted',
            'verified_at' => 'datetime',
            'broken_at' => 'datetime',
        ];
    }
}
