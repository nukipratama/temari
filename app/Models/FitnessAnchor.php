<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property float $vdot
 * @property float $quality_vdot
 * @property int|null $source_activity_id
 * @property string $source_category
 * @property float $source_value_sec
 * @property Carbon $set_at
 * @property int|null $quality_source_activity_id
 * @property string|null $quality_source_category
 * @property float|null $quality_source_value_sec
 * @property Carbon|null $quality_set_at
 * @property Carbon $captured_at
 */
#[Fillable(['user_id', 'vdot', 'quality_vdot', 'source_activity_id', 'source_category', 'source_value_sec', 'set_at', 'quality_source_activity_id', 'quality_source_category', 'quality_source_value_sec', 'quality_set_at', 'captured_at'])]
class FitnessAnchor extends Model
{
    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'vdot' => 'float',
            'quality_vdot' => 'float',
            'source_activity_id' => 'integer',
            'source_value_sec' => 'float',
            'set_at' => 'date:Y-m-d',
            'quality_source_activity_id' => 'integer',
            'quality_source_value_sec' => 'float',
            'quality_set_at' => 'date:Y-m-d',
            'captured_at' => 'datetime',
        ];
    }
}
