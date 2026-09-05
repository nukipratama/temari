<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StreakRestTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * One earned forgiveness of a single runless week against the weekly streak
 * ({@see WeeklySnapshot::consecutiveWeekStreak()}). Unspent while
 * `spent_for_week_ending` is null.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $earned_for_week_ending
 * @property Carbon|null $spent_for_week_ending
 * @property-read User $user
 */
#[Fillable(['user_id', 'earned_for_week_ending', 'spent_for_week_ending'])]
class StreakRestToken extends Model
{
    /** @use HasFactory<StreakRestTokenFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function unspentCountForUser(int $userId): int
    {
        return self::query()->where('user_id', $userId)->whereNull('spent_for_week_ending')->count();
    }

    /**
     * The week-endings a token has already forgiven, as `Y-m-d` keys.
     *
     * @return array<string, true>
     */
    public static function forgivenWeekEndings(int $userId): array
    {
        $weekEndings = self::query()
            ->where('user_id', $userId)
            ->whereNotNull('spent_for_week_ending')
            ->pluck('spent_for_week_ending')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        return array_fill_keys($weekEndings, true);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'earned_for_week_ending' => 'date:Y-m-d',
            'spent_for_week_ending' => 'date:Y-m-d',
        ];
    }
}
