<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExperienceLevel;
use App\Enums\GoalType;
use Database\Factories\TrainingPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Explicit, user-set training preferences — an override, not a mirror of
 * {@see \App\Services\Run\Plan\TrainingBaseline}'s behavior-derived numbers.
 * Every column is nullable and stays that way until the athlete actually
 * sets it: `TrainingBaseline` is the fallback for `sessions_per_week`
 * (behavioral average, or an `experience_level`-seeded default with no
 * history at all) and `WeekPlanBuilder`'s hardcoded day templates are the
 * fallback for `run_days`/`long_run_day`.
 *
 * @property int $id
 * @property int $user_id
 * @property ExperienceLevel|null $experience_level
 * @property int|null $sessions_per_week
 * @property GoalType|null $goal_type
 * @property list<int>|null $run_days
 * @property int|null $long_run_day
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'experience_level',
    'sessions_per_week',
    'goal_type',
    'run_days',
    'long_run_day',
])]
class TrainingPreference extends Model
{
    /** @use HasFactory<TrainingPreferenceFactory> */
    use HasFactory;

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
            'experience_level' => ExperienceLevel::class,
            'sessions_per_week' => 'integer',
            'goal_type' => GoalType::class,
            'run_days' => 'array',
            'long_run_day' => 'integer',
        ];
    }
}
