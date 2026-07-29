<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class AnalysisSubjectAuthorizer
{
    /**
     * Exhaustive on purpose (no `default`): a new AnalysisType without an arm is
     * an UnhandledMatchError, never a silent authorization bypass.
     *
     * @throws AuthorizationException
     */
    public static function authorize(User $user, AnalysisType $type, int $subjectId): void
    {
        $authorized = match ($type) {
            AnalysisType::BriefingMascotVoice,
            AnalysisType::BriefingFeaturedKartuVoice,
            AnalysisType::PersonaSummary,
            AnalysisType::AkuProfileVoice,
            AnalysisType::MonthlyRecap => $subjectId === $user->id,
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsightTechnical,
            AnalysisType::RunInsightSplits,
            AnalysisType::RunInsightZones => self::userOwns(Activity::query(), $subjectId, $user->id),
            AnalysisType::WeeklyRecap => self::userOwns(WeeklySnapshot::query(), $subjectId, $user->id),
            AnalysisType::PrContext => self::userOwns(PersonalRecord::query(), $subjectId, $user->id),
            AnalysisType::CardFlavor => RunCard::query()
                ->whereKey($subjectId)
                ->forUser($user->id)
                ->exists(),
        };

        if (! $authorized) {
            throw new AuthorizationException("Subject does not belong to user (type={$type->value})");
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function userOwns(Builder $query, int $subjectId, int $userId): bool
    {
        return $query->whereKey($subjectId)->where('user_id', $userId)->exists();
    }
}
