<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RaceChangeKind;
use App\Enums\RaceOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * One append-only entry in a race event's history: what its date, target or outcome became, and when.
 *
 * @property int $id
 * @property int $race_goal_id
 * @property int $user_id
 * @property RaceChangeKind $kind
 * @property Carbon|null $race_date
 * @property int|null $goal_time_sec
 * @property RaceOutcome|null $outcome
 * @property int|null $activity_id
 * @property int|null $finish_time_sec
 * @property Carbon $created_at
 */
#[Fillable(['race_goal_id', 'user_id', 'kind', 'race_date', 'goal_time_sec', 'outcome', 'activity_id', 'finish_time_sec'])]
class RaceGoalChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<RaceGoal, $this>
     */
    public function raceGoal(): BelongsTo
    {
        return $this->belongsTo(RaceGoal::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'race_goal_id' => 'integer',
            'user_id' => 'integer',
            'kind' => RaceChangeKind::class,
            'race_date' => 'date:Y-m-d',
            'goal_time_sec' => 'integer',
            'outcome' => RaceOutcome::class,
            'activity_id' => 'integer',
            'finish_time_sec' => 'integer',
        ];
    }
}
