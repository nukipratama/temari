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
     * @throws AuthorizationException
     */
    public static function authorize(User $user, AnalysisType $type, int $subjectId, ?string $discriminator = null): void
    {
        self::authorizeDiscriminator($user, $type, $discriminator);

        $authorized = match ($type) {
            AnalysisType::BriefingMascotVoice,
            AnalysisType::BriefingFeaturedKartuVoice,
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
     * A discriminator that names a *resource* is a second subject and needs the
     * same ownership check: the featured-kartu voice keys off a RunCard id under
     * the triggering user's own subject id, so authorizing the subject alone let
     * a forged trigger have another user's card described in the caller's row.
     * The period-keyed and prohibited types name no resource; their bound is a
     * range, in {@see AnalysisType::discriminatorRules()}.
     *
     * Exhaustive on purpose (no `default`), same as the subject match above.
     *
     * @throws AuthorizationException
     */
    private static function authorizeDiscriminator(User $user, AnalysisType $type, ?string $discriminator): void
    {
        if ($discriminator === null) {
            return;
        }

        $authorized = match ($type) {
            AnalysisType::BriefingFeaturedKartuVoice => RunCard::query()
                ->whereKey((int) $discriminator)
                ->forUser($user->id)
                ->exists(),
            AnalysisType::BriefingMascotVoice,
            AnalysisType::AkuProfileVoice,
            AnalysisType::MonthlyRecap,
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsight,
            AnalysisType::WeeklyRecap,
            AnalysisType::PrContext,
            AnalysisType::CardFlavor,
            AnalysisType::PlanWeekVoice,
            AnalysisType::PlanSeasonVoice,
            // Names a range (30d/90d/12mo), not a resource — bound by
            // discriminatorRules()'s closed set, nothing further to own-check.
            AnalysisType::TrendRead,
            // Names a date, not a resource — bound by discriminatorRules()'s
            // date-shape validation instead.
            AnalysisType::PlanDayVoice => true,
        };

        if (! $authorized) {
            throw new AuthorizationException("Discriminator does not belong to user (type={$type->value})");
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
