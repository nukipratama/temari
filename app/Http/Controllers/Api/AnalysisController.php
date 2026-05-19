<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function trigger(
        Request $request,
        AnalysisService $service,
        string $type,
        int $subjectId,
    ): JsonResponse {
        $analysisType = AnalysisType::tryFrom($type);
        if ($analysisType === null) {
            return $this->unknownType();
        }

        $discriminator = $this->discriminator($request);
        $this->authorizeSubject($this->user($request), $analysisType, $subjectId);

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
            AnalysisType::DailyGreeting,
            AnalysisType::TrendCaption => $subjectId === $user->id,
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
