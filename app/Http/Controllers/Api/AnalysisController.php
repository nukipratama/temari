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
use App\Services\AI\ChainResolver;
use App\Services\AI\RecapPeriod;
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
        ChainResolver $chains,
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

        // The still-running current week/month is window-gated: its recap row is
        // staged Pending but must never be narrated on demand (it would describe
        // an incomplete period). The scheduled command narrates it once the
        // period closes, so a hand-crafted trigger here is a no-op that returns
        // the inert row unchanged. The UI also hides the trigger button for it.
        if ($this->isStillOpenRecapPeriod($analysisType, $subjectId, $discriminator)) {
            return $this->payload($existing, $analysisType, $subjectId, $discriminator);
        }

        if ($existing?->cooldownRemaining() !== null) {
            return $this->payload($existing, $analysisType, $subjectId, $discriminator);
        }

        // The demo login is public, so its "Baca ulang" is served from the
        // rule-based filler rather than the LLM: the button stays live for a
        // reviewer, but an anonymous visitor can never spend Azure tokens.
        // Deliberately ahead of the chain-resume and zone-recompute paths, both
        // of which only exist to shape a real narration.
        if ($user->is_demo) {
            return $this->payload(
                $service->requestRuleBased($analysisType->subjectType(), $subjectId, $analysisType, $discriminator),
                $analysisType,
                $subjectId,
                $discriminator,
            );
        }

        // Chained kinds resume the chain rather than narrating the clicked row
        // in isolation. Only a head regenerate (a Done row that IS the chain
        // head) re-narrates that exact row below. Every other chained click,
        // including a Done non-head row reached by a hand-crafted POST, resumes
        // the earliest unfilled link forward so re-narrating mid-history never
        // desyncs the later blocks that quoted its old narrative.
        $resuming = false;
        if ($analysisType->isChained()
            && ! $chains->isHeadRegenerate($user, $analysisType, $subjectId, $discriminator, $existing)
        ) {
            $resume = $chains->earliestUnfilledLink($user, $analysisType);
            if ($resume !== null) {
                $subjectId = $resume->subjectId;
                $discriminator = $resume->discriminator;
                $resuming = true;
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
            $activity = Activity::with(['detail', 'stream'])->find($subjectId);
            if ($activity !== null) {
                $pipeline->recomputeSummary($activity);
            }
        }

        // Resume = forward-fill only: dispatch the earliest unfilled link without
        // invalidating, so already-Done sibling rows of a resumed activity group
        // are never flipped back to Pending and re-billed. A head regenerate (or a
        // standalone kind's "Baca ulang") invalidates to force a fresh narration.
        $row = $service->request(
            subjectOrType: $analysisType->subjectType(),
            subjectId: $subjectId,
            type: $analysisType,
            discriminator: $discriminator,
            invalidate: ! $resuming,
        );

        return $this->payload($row, $analysisType, $subjectId, $discriminator);
    }

    /**
     * Whether the trigger targets the still-running current recap period (this
     * week or this month), whose recap waits for the scheduled command after the
     * period closes. Only the windowed recap kinds can be open; every other type
     * is always narratable on demand.
     */
    private function isStillOpenRecapPeriod(AnalysisType $type, int $subjectId, ?string $discriminator): bool
    {
        return match ($type) {
            AnalysisType::MonthlyRecap => $discriminator !== null
                && $discriminator > RecapPeriod::lastClosedMonth(),
            AnalysisType::WeeklyRecap => WeeklySnapshot::query()
                ->whereKey($subjectId)
                ->where('week_ending', '>', RecapPeriod::lastClosedWeekEnding())
                ->exists(),
            default => false,
        };
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
            AnalysisType::BriefingSuggestion,
            AnalysisType::BriefingMascotVoice,
            AnalysisType::BriefingFeaturedKartuVoice,
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
