<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\PlanAdaptation;
use App\Models\RunCard;
use App\Models\Season;
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
     * `$discriminator` is accepted but unchecked: no type keys off a *resource*
     * any more. The featured-kartu voice was the only one, and it needed its own
     * ownership check because a RunCard id sat under the caller's own subject id.
     * A future resource-keyed type needs that check added back here; the range or
     * ownership bound is required by AnalysisTypeTest, which fails a type that
     * permits a discriminator and bounds it neither way.
     *
     * @throws AuthorizationException
     */
    public static function authorize(User $user, AnalysisType $type, int $subjectId, ?string $discriminator = null): void
    {
        $authorized = match ($type) {
            AnalysisType::BriefingMascotVoice,
            AnalysisType::AkuProfileVoice,
            AnalysisType::MonthlyRecap,
            AnalysisType::TrendRead,
            AnalysisType::PlanDayVoice => $subjectId === $user->id,
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsight => self::userOwns(Activity::query(), $subjectId, $user->id),
            AnalysisType::WeeklyRecap => self::userOwns(WeeklySnapshot::query(), $subjectId, $user->id),
            AnalysisType::PrContext => self::userOwns(PersonalRecord::query(), $subjectId, $user->id),
            AnalysisType::CardFlavor => RunCard::query()
                ->whereKey($subjectId)
                ->forUser($user->id)
                ->exists(),
            AnalysisType::PlanWeekVoice => self::userOwns(PlanAdaptation::query(), $subjectId, $user->id),
            AnalysisType::PlanSeasonVoice => self::userOwns(Season::query(), $subjectId, $user->id),
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
