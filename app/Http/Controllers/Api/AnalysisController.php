<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TriggerAnalysisRequest;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function trigger(
        TriggerAnalysisRequest $request,
        AnalysisService $service,
        ActivityPipeline $pipeline,
        string $type,
        int $subjectId,
    ): JsonResponse {
        // Validation in TriggerAnalysisRequest guarantees a known type.
        $analysisType = AnalysisType::from($type);

        $user = $this->user($request);
        $this->authorizeSubject($user, $analysisType, $subjectId);
        $discriminator = $request->discriminator();

        $existing = Analysis::query()
            ->forSubject($analysisType->subjectType(), $subjectId, $analysisType, $discriminator)
            ->first();

        if ($existing?->cooldownRemaining() !== null) {
            return $this->payload($existing, $analysisType, $subjectId, $discriminator);
        }

        // A manual "Baca ulang" on a zone-dependent run block recomputes its
        // stream summary from the already-stored streams (no Strava calls) so
        // the regenerated narration reflects the user's current zones. Skipped
        // unless the user has a custom profile, since without one the stored
        // summary already used the config defaults that hrProfile() returns.
        if ($analysisType->isZoneDependent()
            && $analysisType->subjectType() === Activity::class
            && $user->runnerProfile !== null
        ) {
            $activity = Activity::find($subjectId);
            if ($activity !== null) {
                $pipeline->recomputeSummary($activity);
            }
        }

        $row = $service->request(
            subjectOrType: $analysisType->subjectType(),
            subjectId: $subjectId,
            type: $analysisType,
            discriminator: $discriminator,
            invalidate: true,
        );

        return $this->payload($row, $analysisType, $subjectId, $discriminator);
    }

    public function show(
        Request $request,
        string $type,
        int $subjectId,
    ): JsonResponse {
        $analysisType = AnalysisType::tryFrom($type);
        if ($analysisType === null) {
            return $this->unknownType();
        }

        $discriminator = $this->discriminator($request);
        $this->authorizeSubject($this->user($request), $analysisType, $subjectId);

        $row = Analysis::query()
            ->forSubject($analysisType->subjectType(), $subjectId, $analysisType, $discriminator)
            ->first();

        return $this->payload($row, $analysisType, $subjectId, $discriminator);
    }

    private function payload(?Analysis $row, AnalysisType $type, int $subjectId, ?string $discriminator): JsonResponse
    {
        return response()->json(
            Analysis::toPayload($row, $type, $type->subjectType(), $subjectId, $discriminator),
        );
    }

    private function unknownType(): JsonResponse
    {
        return response()->json(['error' => 'unknown_analysis_type'], 422);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthorizationException('Unauthenticated');
        }

        return $user;
    }

    private function discriminator(Request $request): ?string
    {
        $value = (string) $request->query('discriminator', '');

        return $value === '' ? null : $value;
    }

    private function authorizeSubject(User $user, AnalysisType $type, int $subjectId): void
    {
        $authorized = match ($type) {
            AnalysisType::BriefingHeadline,
            AnalysisType::BriefingSuggestion,
            AnalysisType::BriefingMascotVoice,
            AnalysisType::BriefingFeaturedKartuVoice,
            AnalysisType::DailyGreeting,
            AnalysisType::TrendCaption,
            AnalysisType::PersonaSummary,
            AnalysisType::AkuProfileVoice,
            AnalysisType::MonthlyRecap => $subjectId === $user->id,
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsightTechnical,
            AnalysisType::RunInsightSplits,
            AnalysisType::RunInsightZones => $this->userOwns(Activity::query(), $subjectId, $user->id),
            AnalysisType::WeeklyRecap => $this->userOwns(WeeklySnapshot::query(), $subjectId, $user->id),
            AnalysisType::PrContext => $this->userOwns(PersonalRecord::query(), $subjectId, $user->id),
            AnalysisType::CardFlavor => RunCard::query()
                ->whereKey($subjectId)
                ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
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
    private function userOwns(Builder $query, int $subjectId, int $userId): bool
    {
        return $query->whereKey($subjectId)->where('user_id', $userId)->exists();
    }
}
