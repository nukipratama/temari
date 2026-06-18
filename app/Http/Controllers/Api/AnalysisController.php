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

        // Chained kinds resume the chain rather than narrating the clicked row
        // in isolation. Only a head regenerate (a Done row that IS the chain
        // head) re-narrates that exact row below. Every other chained click,
        // including a Done non-head row reached by a hand-crafted POST, resumes
        // the earliest unfilled link forward so re-narrating mid-history never
        // desyncs the later blocks that quoted its old narrative.
        $resuming = false;
        if ($analysisType->isChained()
            && ! $this->isChainHeadRegenerate($user, $analysisType, $subjectId, $discriminator, $existing)
        ) {
            $resume = $this->earliestUnfilledChainLink($user, $analysisType);
            if ($resume !== null) {
                [$subjectId, $discriminator, $existing] = $resume;
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
            $activity = Activity::find($subjectId);
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

    /**
     * Whether the clicked row is a legitimate head regenerate: a Done row that
     * is the latest narrated link of the user's chain. Only the head may
     * regenerate; re-narrating a mid-history link would desync later blocks.
     * Returns false for non-Done rows and for unknown chained types (which fall
     * through to the resume path).
     *
     * Keying differs per kind: WeeklyRecap is keyed by the WeeklySnapshot
     * subject id; MonthlyRecap is keyed by the discriminator month (Y-m) under a
     * single user subject, so its head is matched on the discriminator.
     */
    private function isChainHeadRegenerate(User $user, AnalysisType $type, int $subjectId, ?string $discriminator, ?Analysis $existing): bool
    {
        if ($existing?->status !== AnalysisStatus::Done) {
            return false;
        }

        return match ($type) {
            AnalysisType::WeeklyRecap => $subjectId === $this->weeklyChainHeadId($user),
            AnalysisType::MonthlyRecap => $discriminator !== null && $discriminator === $this->monthlyChainHeadMonth($user),
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsightTechnical,
            AnalysisType::RunInsightSplits,
            AnalysisType::RunInsightZones => $subjectId === Activity::latestIdForUser($user->id),
            default => false,
        };
    }

    /**
     * The earliest unfilled link of the user's chain for a chained type, as a
     * `[subjectId, discriminator, existingRow]` tuple, or null when there is
     * nothing earlier to resume (the clicked row is then used as-is). "Unfilled"
     * = no Done recap; walking from oldest forward fills the chronological gap so
     * each successor still reads a Done predecessor. Returns null for an unknown
     * chained type so the caller keeps the clicked row's identity.
     *
     * @return array{0: int, 1: string|null, 2: \App\Models\AI\Analysis|null}|null
     */
    private function earliestUnfilledChainLink(User $user, AnalysisType $type): ?array
    {
        return match ($type) {
            AnalysisType::WeeklyRecap => $this->earliestUnfilledWeeklyLink($user),
            AnalysisType::MonthlyRecap => $this->earliestUnfilledMonthlyLink($user),
            AnalysisType::PostRunSpeech,
            AnalysisType::RunInsightTechnical,
            AnalysisType::RunInsightSplits,
            AnalysisType::RunInsightZones => $this->earliestUnfilledActivityLink($user, $type),
            default => null,
        };
    }

    /** @return array{0: int, 1: string|null, 2: \App\Models\AI\Analysis|null}|null */
    private function earliestUnfilledWeeklyLink(User $user): ?array
    {
        $lastWeekEnding = RecapPeriod::lastClosedWeekEnding();

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

        return [(int) $earliest->id, null, $existing];
    }

    /**
     * The monthly chain's earliest unfilled (not Done) month for the user. The
     * chain links are the pre-staged Analysis rows themselves (keyed by the Y-m
     * discriminator under the user subject), so this walks those rows rather than
     * a per-month subject table. The still-running current month is excluded (its
     * row is staged Pending but inert until the month closes), so resuming never
     * narrates an incomplete month.
     *
     * @return array{0: int, 1: string|null, 2: \App\Models\AI\Analysis|null}|null
     */
    private function earliestUnfilledMonthlyLink(User $user): ?array
    {
        $earliest = Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('status', '!=', AnalysisStatus::Done)
            ->where('discriminator', '<=', RecapPeriod::lastClosedMonth())
            ->orderBy('discriminator')
            ->first();

        if ($earliest === null) {
            return null;
        }

        return [$user->id, $earliest->discriminator, $earliest];
    }

    /**
     * The per-activity chain's earliest unfilled (not Done) link for the clicked
     * type. The chain is keyed by the Activity id (discriminator null) and
     * ordered by start_date_local, so this walks the user's activities oldest
     * first for the first one whose clicked-type row is not Done, resuming the
     * group from there. Returns null when every activity's row is already Done
     * (the clicked row is then used as-is, which the head-regenerate path
     * handles).
     *
     * @return array{0: int, 1: string|null, 2: \App\Models\AI\Analysis|null}|null
     */
    private function earliestUnfilledActivityLink(User $user, AnalysisType $type): ?array
    {
        $earliest = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereDoesntHave('analyses', fn ($query) => $query
                ->where('analysis_type', $type)
                ->where('status', AnalysisStatus::Done))
            ->orderBy('activity_details.start_date_local')
            ->select('activities.id')
            ->first();

        if ($earliest === null) {
            return null;
        }

        $existing = Analysis::query()
            ->forSubject(Activity::class, (int) $earliest->id, $type)
            ->first();

        return [(int) $earliest->id, null, $existing];
    }

    /** The WeeklySnapshot id of the user's latest completed running week, or null. */
    private function weeklyChainHeadId(User $user): ?int
    {
        $lastWeekEnding = RecapPeriod::lastClosedWeekEnding();

        $headId = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', $lastWeekEnding)
            ->where('runs', '>', 0)
            ->orderByDesc('week_ending')
            ->value('id');

        return $headId === null ? null : (int) $headId;
    }

    /**
     * The latest closed month (Y-m) the user has a MonthlyRecap row for, or null.
     * Capped at the last fully-closed month so the still-running current month's
     * inert Pending row is never treated as the regenerable chain head.
     */
    private function monthlyChainHeadMonth(User $user): ?string
    {
        return Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('discriminator', '<=', RecapPeriod::lastClosedMonth())
            ->orderByDesc('discriminator')
            ->value('discriminator');
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
