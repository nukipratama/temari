<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property string $fingerprint
 * @property int $policy_version
 * @property array<string, mixed> $original
 * @property array<string, mixed> $effective
 */
#[Fillable(['user_id', 'date', 'fingerprint', 'policy_version', 'original', 'effective', 'created_at'])]
class RecommendationRevision extends Model
{
    public const null UPDATED_AT = null;

    #[Override]
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Recommendation revisions are immutable.');
        });
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'date' => 'date:Y-m-d', 'policy_version' => 'integer', 'original' => 'array', 'effective' => 'array'];
    }
}
