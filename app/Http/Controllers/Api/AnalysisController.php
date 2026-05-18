<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Auth\Access\AuthorizationException;
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
            return response()->json(['error' => 'unknown_analysis_type'], 422);
        }

        $discriminator = $this->discriminator($request);
        $subjectType = $analysisType->subjectType();
        $this->authorizeSubject($request, $analysisType, $subjectId);

        $row = $service->request(
            subjectOrType: $subjectType,
            subjectId: $subjectId,
            type: $analysisType,
            discriminator: $discriminator,
            force: true,
        );

        return response()->json(Analysis::toPayload($row, $analysisType, $subjectType, $subjectId, $discriminator));
    }

    public function show(
        Request $request,
        string $type,
        int $subjectId,
    ): JsonResponse {
        $analysisType = AnalysisType::tryFrom($type);
        if ($analysisType === null) {
            return response()->json(['error' => 'unknown_analysis_type'], 422);
        }

        $discriminator = $this->discriminator($request);
        $subjectType = $analysisType->subjectType();
        $this->authorizeSubject($request, $analysisType, $subjectId);

        $row = Analysis::query()
            ->forSubject($subjectType, $subjectId, $analysisType, $discriminator)
            ->first();

        return response()->json(Analysis::toPayload($row, $analysisType, $subjectType, $subjectId, $discriminator));
    }

    private function discriminator(Request $request): ?string
    {
        $value = (string) $request->query('discriminator', '');

        return $value === '' ? null : $value;
    }

    private function authorizeSubject(Request $request, AnalysisType $type, int $subjectId): void
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthorizationException('Unauthenticated');
        }

        $authorized = match ($type) {
            AnalysisType::BriefingHeadline,
            AnalysisType::BriefingSuggestion,
            AnalysisType::DailyGreeting,
            AnalysisType::TrendCaption => $subjectId === $user->id,
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsightTechnical,
            AnalysisType::RunInsightSplits,
            AnalysisType::RunInsightZones => Activity::query()
                ->where('id', $subjectId)
                ->where('user_id', $user->id)
                ->exists(),
            AnalysisType::WeeklyRecap => WeeklySnapshot::query()
                ->where('id', $subjectId)
                ->where('user_id', $user->id)
                ->exists(),
            AnalysisType::PrContext => PersonalRecord::query()
                ->where('id', $subjectId)
                ->where('user_id', $user->id)
                ->exists(),
            AnalysisType::CardFlavor => RunCard::query()
                ->whereKey($subjectId)
                ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
                ->exists(),
        };

        if (! $authorized) {
            throw new AuthorizationException("Subject does not belong to user (type={$type->value})");
        }
    }
}
