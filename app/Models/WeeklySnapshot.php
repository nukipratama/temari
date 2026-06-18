<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\AI\Analysis;
use Database\Factories\WeeklySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $week_ending
 * @property float|null $distance_km
 * @property int|null $runs
 * @property int|null $moving_time_sec
 * @property float|null $weekly_trimp
 * @property float|null $atl_7d
 * @property float|null $ctl_42d
 * @property float|null $form
 * @property string|null $form_status
 * @property float|null $avg_decoupling
 * @property float|null $monotony
 * @property float|null $strain
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'week_ending',
    'distance_km',
    'runs',
    'moving_time_sec',
    'weekly_trimp',
    'atl_7d',
    'ctl_42d',
    'form',
    'form_status',
    'avg_decoupling',
    'monotony',
    'strain',
])]
class WeeklySnapshot extends Model
{
    /** @use HasFactory<WeeklySnapshotFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Analysis rows (e.g. the WeeklyRecap narration) whose subject is this
     * snapshot. The Analysis table stores `subject_type`/`subject_id`, so this
     * is a class-name morph. Lets chain queries filter by recap status with a
     * user-scoped correlated subquery instead of plucking global subject ids.
     *
     * @return MorphMany<Analysis, $this>
     */
    public function analyses(): MorphMany
    {
        return $this->morphMany(Analysis::class, 'subject');
    }

    /**
     * Counts the run of weeks (each with runs > 0) that are consecutive,
     * walking back from the most recent `week_ending`. Weeks are consecutive
     * when their `week_ending` dates are exactly 7 days apart; the first gap
     * ends the streak. Non-adjacent weeks (e.g. weeks 1, 5, 9) yield a streak
     * of 1, not 3.
     */
    public static function consecutiveWeekStreak(int $userId): int
    {
        /** @var list<Carbon> $weekEndings */
        $weekEndings = self::query()
            ->where('user_id', $userId)
            ->where('runs', '>', 0)
            ->orderByDesc('week_ending')
            ->pluck('week_ending')
            ->all();

        if ($weekEndings === []) {
            return 0;
        }

        $streak = 1;
        $previous = $weekEndings[0]->copy()->startOfDay();

        foreach (\array_slice($weekEndings, 1) as $weekEnding) {
            $current = $weekEnding->copy()->startOfDay();
            if (! $previous->copy()->subDays(7)->equalTo($current)) {
                break;
            }

            $streak++;
            $previous = $current;
        }

        return $streak;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'week_ending' => 'date:Y-m-d',
            'distance_km' => 'float',
            'runs' => 'integer',
            'moving_time_sec' => 'integer',
            'weekly_trimp' => 'float',
            'atl_7d' => 'float',
            'ctl_42d' => 'float',
            'form' => 'float',
            'avg_decoupling' => 'float',
            'monotony' => 'float',
            'strain' => 'float',
        ];
    }
}
