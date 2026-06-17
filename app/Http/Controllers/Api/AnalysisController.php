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
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        // Chained kinds resume the chain rather than narrating the clicked row
        // in isolation. Only a head regenerate (a Done row that IS the chain
        // head) re-narrates that exact row below. Every other chained click,
        // including a Done non-head row reached by a hand-crafted POST, resumes
        // the earliest unfilled link forward so re-narrating mid-history never
        // desyncs the later blocks that quoted its old narrative.
        if ($analysisType->isChained()
            && ! $this->isChainHeadRegenerate($user, $analysisType, $subjectId, $existing)
        ) {
            $resume = $this->earliestUnfilledChainLink($user, $analysisType);
            if ($resume !== null) {
                [$subjectId, $existing] = $resume;
            }
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

    /**
     * Whether the clicked row is a legitimate head regenerate: a Done row that
     * is the latest narrated link of the user's chain. Only the head may
     * regenerate; re-narrating a mid-history link would desync later blocks.
     * Returns false for non-Done rows and for unknown chained types (which fall
     * through to the resume path).
     */
    private function isChainHeadRegenerate(User $user, AnalysisType $type, int $subjectId, ?Analysis $existing): bool
    {
        if ($existing?->status !== AnalysisStatus::Done || $type !== AnalysisType::WeeklyRecap) {
            return false;
        }

        $lastWeekEnding = Carbon::today()->subWeek()->endOfWeek(Carbon::SUNDAY)->startOfDay()->toDateString();

        $headId = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->orderByDesc('week_ending')
            ->value('id');

        return $headId !== null && (int) $headId === $subjectId;
    }

    /**
     * The earliest unfilled link of the user's chain for a chained type, as a
     * `[subjectId, existingRow]` tuple, or null when there is nothing earlier to
     * resume (the clicked row is then used as-is). "Unfilled" = no Done recap;
     * walking from oldest forward fills the chronological gap so each successor
     * still reads a Done predecessor. Returns null for an unknown chained type
     * so the caller keeps the clicked row's identity.
     *
     * @return array{0: int, 1: \App\Models\AI\Analysis|null}|null
     */
    private function earliestUnfilledChainLink(User $user, AnalysisType $type): ?array
    {
        if ($type !== AnalysisType::WeeklyRecap) {
            return null;
        }

        $lastWeekEnding = Carbon::today()->subWeek()->endOfWeek(Carbon::SUNDAY)->startOfDay()->toDateString();

        $earliest = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Done))
            ->orderBy('week_ending')
            ->first();

        if ($earliest === null) {
            return null;
        }

        $existing = Analysis::query()
            ->forSubject(WeeklySnapshot::class, $earliest->id, AnalysisType::WeeklyRecap)
            ->first();

        return [(int) $earliest->id, $existing];
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
    private function userOwns(Builder $query, int $subjectId, int $userId): bool
    {
        return $query->whereKey($subjectId)->where('user_id', $userId)->exists();
    }
}
