<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The ai_analyses subject_type to owning-user mapping. The `*_user_*` string
 * subject types are absent from the map: they store the user id as subject_id.
 * The batched and SQL-filter shapes keep a row list off a find()-per-row.
 */
final class AnalysisSubjectMap
{
    /** @return array<string, array{0: Builder<covariant Model>, 1: string, 2: string}> */
    private static function lookups(): array
    {
        return [
            Activity::class => [Activity::query(), 'id', 'user_id'],
            WeeklySnapshot::class => [WeeklySnapshot::query(), 'id', 'user_id'],
            PersonalRecord::class => [PersonalRecord::query(), 'id', 'user_id'],
            RunCard::class => [
                RunCard::query()->joinSub(
                    Activity::query()->select('id', 'user_id'),
                    'activities',
                    'activities.id',
                    '=',
                    'run_cards.activity_id',
                ),
                'run_cards.id',
                'activities.user_id',
            ],
        ];
    }

    public static function ownerId(string $subjectType, int $subjectId): ?int
    {
        $lookup = self::lookups()[$subjectType] ?? null;
        if ($lookup === null) {
            return $subjectId;
        }

        [$query, $idColumn, $userIdColumn] = $lookup;
        $userId = $query->where($idColumn, $subjectId)->value($userIdColumn);

        return is_numeric($userId) ? (int) $userId : null;
    }

    /**
     * @param  Collection<int, Analysis>  $rows
     * @return array<int, int|null>  Keyed by row id.
     */
    public static function ownerIdsForRows(Collection $rows): array
    {
        $ownerIds = [];

        foreach ($rows->groupBy('subject_type') as $subjectType => $group) {
            $lookup = self::lookups()[(string) $subjectType] ?? null;

            $userIds = [];
            if ($lookup !== null) {
                [$query, $idColumn, $userIdColumn] = $lookup;
                $userIds = $query
                    ->whereIn($idColumn, $group->pluck('subject_id')->unique()->all())
                    ->pluck($userIdColumn, $idColumn)
                    ->all();
            }

            foreach ($group as $row) {
                $ownerIds[$row->id] = $lookup === null
                    ? $row->subject_id
                    : (is_numeric($userIds[$row->subject_id] ?? null) ? (int) $userIds[$row->subject_id] : null);
            }
        }

        return $ownerIds;
    }

    /**
     * @param  Builder<Analysis>  $query
     * @return Builder<Analysis>
     */
    public static function whereOwnedBy(Builder $query, int $userId): Builder
    {
        $lookups = self::lookups();

        return $query->where(function (Builder $outer) use ($lookups, $userId): void {
            foreach ($lookups as $subjectType => [$subjectQuery, $idColumn, $userIdColumn]) {
                $outer->orWhere(function (Builder $inner) use ($subjectType, $subjectQuery, $idColumn, $userIdColumn, $userId): void {
                    $inner
                        ->where('subject_type', $subjectType)
                        ->whereIn('subject_id', $subjectQuery->where($userIdColumn, $userId)->select($idColumn));
                });
            }

            $outer->orWhere(function (Builder $inner) use ($lookups, $userId): void {
                $inner
                    ->whereNotIn('subject_type', array_keys($lookups))
                    ->where('subject_id', $userId);
            });
        });
    }
}
