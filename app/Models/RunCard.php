<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Badge;
use App\Enums\Rarity;
use Database\Factories\RunCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $activity_id
 * @property Rarity $rarity
 * @property array<int, string> $badges
 * @property string $special_move
 * @property bool $pr_set
 * @property string|null $share_image_path
 * @property-read Activity $activity
 */
#[Fillable([
    'activity_id',
    'rarity',
    'badges',
    'special_move',
    'pr_set',
    'share_image_path',
])]
class RunCard extends Model
{
    /** @use HasFactory<RunCardFactory> */
    use HasFactory;

    /**
     * Count how many of this user's cards carry each tracked badge.
     * Single query, counts in PHP to avoid N per-badge round-trips.
     *
     * @return array<string, int>
     */
    public static function badgeCountsForUser(int $userId): array
    {
        $tracked = Badge::tracked();
        $trackedValues = array_map(fn (Badge $b): string => $b->value, $tracked);
        $counts = array_fill_keys($trackedValues, 0);

        $rows = self::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $userId))
            ->select('badges')
            ->lazy();

        foreach ($rows as $row) {
            foreach ($row->badges ?? [] as $badge) {
                if (isset($counts[$badge])) {
                    $counts[$badge]++;
                }
            }
        }

        return $counts;
    }

    /**
     * Every {@see Badge} case's count (not just {@see Badge::tracked()} —
     * the unlock catalog's narrower subset), for the badge board. Optionally
     * scoped to cards whose activity fell within `[$from, $to]` for the
     * board's "this season" row. Kept alongside {@see self::badgeCountsForUser()}
     * rather than widening it, so every existing lifetime (tracked-only)
     * call site is untouched.
     *
     * @return array<string, int>
     */
    public static function allBadgeCountsForUser(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $counts = array_fill_keys(array_map(fn (Badge $b): string => $b->value, Badge::cases()), 0);

        $rows = self::query()
            ->whereHas('activity', function ($q) use ($userId, $from, $to): void {
                $q->where('user_id', $userId);
                if ($from !== null && $to !== null) {
                    $q->whereHas('detail', fn ($d) => $d->whereBetween('start_date_local', [
                        $from->copy()->startOfDay(),
                        $to->copy()->endOfDay(),
                    ]));
                }
            })
            ->select('badges')
            ->lazy();

        foreach ($rows as $row) {
            foreach ($row->badges ?? [] as $badge) {
                if (isset($counts[$badge])) {
                    $counts[$badge]++;
                }
            }
        }

        return $counts;
    }

    /**
     * The date each badge slug was first earned by this user, oldest
     * occurrence only, with the rarity of the card that earned it — a "first
     * time" story for Trends' badge chips, not a log of every repeat.
     *
     * @return array<string, array{date: string, rarity: string}>
     */
    public static function firstEarnedBadgesForUser(int $userId): array
    {
        $first = [];

        self::query()
            ->join('activities', 'activities.id', '=', 'run_cards.activity_id')
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $userId)
            ->orderBy('activity_details.start_date_local')
            ->select('run_cards.badges', 'run_cards.rarity', 'activity_details.start_date_local')
            ->lazy()
            ->each(function (self $row) use (&$first): void {
                /** @var string $startDateLocal */
                $startDateLocal = $row->getAttribute('start_date_local');
                foreach ($row->badges ?? [] as $badge) {
                    $first[$badge] ??= ['date' => $startDateLocal, 'rarity' => $row->rarity->value];
                }
            });

        return $first;
    }

    /**
     * Cards owned by the given user (i.e. whose source activity belongs to them).
     *
     * @param  Builder<RunCard>  $query
     * @return Builder<RunCard>
     */
    #[Scope]
    protected function forUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('activity', fn ($q) => $q->where('user_id', $userId));
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activity_id' => 'integer',
            'badges' => 'array',
            'rarity' => Rarity::class,
            'pr_set' => 'boolean',
        ];
    }
}
