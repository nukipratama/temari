<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisSubjectAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What a piece of feedback is about. The two things a runner can see and
 * disagree with: a day the plan prescribed, and something Temari said.
 */
enum FeedbackSubject: string
{
    case PlanDay = 'plan_day';
    case Narration = 'narration';

    /**
     * A narration's owner is the owner of whatever the Analysis row is about,
     * so it is resolved through the same authorizer the trigger endpoint uses
     * rather than a second copy of the per-type ownership rules.
     */
    public function isOwnedBy(User $user, int $subjectId): bool
    {
        return match ($this) {
            self::PlanDay => PlannedSession::query()
                ->whereKey($subjectId)
                ->where('user_id', $user->id)
                ->exists(),
            self::Narration => self::narrationIsOwnedBy($user, $subjectId),
        };
    }

    private static function narrationIsOwnedBy(User $user, int $subjectId): bool
    {
        $analysis = Analysis::query()->find($subjectId);

        if ($analysis === null) {
            return false;
        }

        try {
            AnalysisSubjectAuthorizer::authorize(
                $user,
                $analysis->analysis_type,
                $analysis->subject_id,
                $analysis->discriminator,
            );
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }
}
