<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TriggerAnalysisRequest;
use App\Http\Resources\AnalysisResource;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisSubjectAuthorizer;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use App\Services\AI\ChainResolver;
use App\Services\Run\Metrics\SummaryRecomputer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisController extends Controller
{
    public function trigger(
        TriggerAnalysisRequest $request,
        AnalysisService $service,
        SummaryRecomputer $summaries,
        ChainResolver $chains,
        BackfillAgeGate $ages,
        string $type,
        int $subjectId,
    ): JsonResponse {
        // Validation in TriggerAnalysisRequest guarantees a known type.
        $analysisType = AnalysisType::from($type);

        $user = $this->user($request);
        $discriminator = $request->discriminator();
        AnalysisSubjectAuthorizer::authorize($user, $analysisType, $subjectId, $discriminator);

        $existing = Analysis::query()
            ->forSubject($analysisType->subjectType(), $subjectId, $analysisType, $discriminator)
            ->first();

        if ($service->isStillOpenRecapPeriod($analysisType, $subjectId, $discriminator)) {
            return $this->payload($existing, $analysisType, $subjectId, $discriminator);
        }

        if ($existing?->cooldownRemaining() !== null) {
            return $this->payload($existing, $analysisType, $subjectId, $discriminator);
        }

        if ($service->shouldServeRuleBased($user)) {
            return $this->payload(
                $service->requestRuleBased($analysisType->subjectType(), $subjectId, $analysisType, $discriminator),
                $analysisType,
                $subjectId,
                $discriminator,
            );
        }

        if ($ages->blocksManualTrigger($analysisType, $subjectId, $discriminator)) {
            return $this->payload(
                $service->requestRuleBased($analysisType->subjectType(), $subjectId, $analysisType, $discriminator, refillDone: false),
                $analysisType,
                $subjectId,
                $discriminator,
            );
        }

        if ($service->generationPaused()) {
            return $this->payload($existing, $analysisType, $subjectId, $discriminator)
                ->setStatusCode(409);
        }

        $resume = $service->shouldResumeChain($user, $analysisType, $subjectId, $discriminator, $existing)
            ? $chains->earliestUnfilledLink($user, $analysisType)
            : null;

        if ($resume !== null) {
            $subjectId = $resume->subjectId;
            $discriminator = $resume->discriminator;
        }

        if ($service->shouldRecomputeZoneSummary($user, $analysisType)) {
            $summaries->recomputeFromStoredStreams($subjectId);
        }

        $row = $service->request(
            subjectOrType: $analysisType->subjectType(),
            subjectId: $subjectId,
            type: $analysisType,
            discriminator: $discriminator,
            invalidate: $resume === null,
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
        AnalysisSubjectAuthorizer::authorize($this->user($request), $analysisType, $subjectId, $discriminator);

        $row = Analysis::query()
            ->forSubject($analysisType->subjectType(), $subjectId, $analysisType, $discriminator)
            ->first();

        return $this->payload($row, $analysisType, $subjectId, $discriminator);
    }

    private function payload(?Analysis $row, AnalysisType $type, int $subjectId, ?string $discriminator): JsonResponse
    {
        return response()->json(AnalysisResource::make($row, $type, $subjectId, $discriminator));
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
}
