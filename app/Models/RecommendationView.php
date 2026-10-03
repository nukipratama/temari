<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Override;

/**
 * @property int $id
 * @property int $recommendation_revision_id
 * @property string $observation_id
 * @property Carbon $shown_at
 */
#[Fillable(['recommendation_revision_id', 'observation_id', 'shown_at'])]
#[WithoutTimestamps]
class RecommendationView extends Model
{
    #[Override]
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Recommendation views are immutable.');
        });
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['recommendation_revision_id' => 'integer', 'shown_at' => 'datetime'];
    }
}
