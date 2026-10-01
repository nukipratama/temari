<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PerformanceEvidenceKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $activity_id
 * @property int|null $race_goal_id
 * @property PerformanceEvidenceKind $kind
 * @property int $distance_m
 * @property int $elapsed_time_sec
 * @property Carbon $performed_on
 * @property Carbon $confirmed_at
 */
#[Fillable(['user_id', 'activity_id', 'race_goal_id', 'kind', 'distance_m', 'elapsed_time_sec', 'performed_on', 'confirmed_at'])]
class PerformanceEvidence extends Model
{
    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'activity_id' => 'integer', 'race_goal_id' => 'integer',
            'kind' => PerformanceEvidenceKind::class, 'distance_m' => 'integer', 'elapsed_time_sec' => 'integer',
            'performed_on' => 'date:Y-m-d', 'confirmed_at' => 'datetime',
        ];
    }
}
