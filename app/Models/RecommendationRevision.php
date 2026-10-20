<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Override;

/**
 * @phpstan-type Segment array{key: string, minutes: float|null, km: float|null, zone: string, pace_label: string, pace_sec_per_km: int|null}
 * @phpstan-type OriginalAdvice array{session_type?: string, phase?: string, hard_minutes?: int|null, distance_km?: float|int|null, reason?: string|null, segments?: list<Segment>}
 * @phpstan-type EffectiveAdvice array{session_type: string, distance_km: float|int|null, segments?: list<Segment>, paces?: array{easy: int, marathon: int, threshold: int, interval: int}|null, skipped: bool, reason?: string|null, readiness_assessment?: array<string, mixed>|null}
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property string $fingerprint
 * @property int $policy_version
 * @property OriginalAdvice $original
 * @property EffectiveAdvice $effective
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
