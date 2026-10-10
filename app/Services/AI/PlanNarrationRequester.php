<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Models\Season;
use App\Models\User;
use App\Services\Run\Plan\ClampNarrationContext;
use App\Services\Run\Plan\SustainedAheadOfRacePace;
use App\Support\Cooldown;
use Illuminate\Support\Carbon;
use App\Actions\Run\Plan\ResolveSeasonAction;

/**
 * Requests fresh season narration for the current week and reads it back for
 * the Plan page.
 */
final readonly class PlanNarrationRequester
{
    public function __construct(
        private AnalysisService $analysisService,
        private ClampNarrationContext $clampContext,
        private ResolveSeasonAction $season,
        private SustainedAheadOfRacePace $sustainedAheadOfRacePace,
    ) {
    }

    /**
     * The narrated explanation for today's step-down, or null while none has
     * landed. Callers fall back to the clamp's own templated note, which is why
     * this returns only a Done row and never a pending one.
     */
    public function clampVoiceFor(User $user, Carbon $today): ?string
    {
        $context = $this->clampContext->forUserOn($user->id, $today);
        if ($context === null) {
            return null;
        }

        $analysis = Analysis::query()
            ->forSubject(AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::PlanClampVoice, $today->toDateString())
            ->where('status', AnalysisStatus::Done)
            ->first(['content', 'content_fingerprint']);
        if ($analysis === null) {
            return null;
        }

        $expected = MaterialFingerprint::forClamp($context['ceiling'], $context['clamped_to'], $context['has_run_today'], $context['readiness_reasons']);

        return $analysis->content_fingerprint === $expected ? $analysis->content : null;
    }

    /** Requests a current clamp's explanation through the shared briefing side effects. */
    public function requestClampVoice(User $user, Carbon $today): bool
    {
        $context = $this->clampContext->forUserOn($user->id, $today);
        if ($context === null) {
            return false;
        }

        $discriminator = $today->toDateString();
        $stamped = Analysis::query()
            ->forSubject(AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::PlanClampVoice, $discriminator)
            ->where('status', AnalysisStatus::Done)
            ->value('content_fingerprint');
        $expected = MaterialFingerprint::forClamp($context['ceiling'], $context['clamped_to'], $context['has_run_today'], $context['readiness_reasons']);

        $this->analysisService->request(
            subjectOrType: AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::PlanClampVoice,
            discriminator: $discriminator,
            invalidate: $stamped !== null && $stamped !== $expected,
        );

        return true;
    }

    /**
     * Requests the week's narration unless a recent settings change already
     * did, and reports whether it fired. Guards **only** the voice: callers
     * regenerate the plan itself unconditionally, since a plan left describing
     * a race the athlete no longer has is worse than a day-old blurb. The
     * manual regenerate button, `plan:regenerate` and the first-week paths all
     * bypass this — see `docs/features/plan-periodizer.md`.
     */
    public function requestForCurrentWeekUnlessCoolingDown(User $user, Carbon $today): bool
    {
        $cooldown = $this->narrationCooldown($user);
        if ($cooldown->remaining() !== null) {
            return false;
        }

        $cooldown->start();
        $this->requestForCurrentWeek($user, $today);

        return true;
    }

    private function narrationCooldown(User $user): Cooldown
    {
        return new Cooldown("plan-narration:{$user->id}", Cooldown::PLAN_NARRATION_WINDOW_SECONDS);
    }

    /**
     * Requests narration for the current season.
     *
     * Season narration relies on AnalysisService's own idempotency: an
     * unchanged season's already-Done content is left alone rather than re-billed.
     */
    public function requestForCurrentWeek(User $user, Carbon $today): void
    {
        $this->requestWeek($user, $today);
    }

    /** The brand-new account's one narration of its first week. */
    public function requestForFirstWeek(User $user, Carbon $today): void
    {
        $this->requestWeek($user, $today);
    }

    /**
     * Requests the season row, invalidating when either the sustained-ahead
     * signal or the current week's recorded adaptation has changed since the
     * row's last fingerprint. A never-fingerprinted Done row counts as changed,
     * so it gets one chance to pick up the current tool context.
     */
    private function requestWeek(User $user, Carbon $today): void
    {
        $season = $this->currentSeason($user);
        if ($season === null) {
            return;
        }

        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $adaptation = PlanAdaptation::query()
            ->where('user_id', $user->id)
            ->where('week_start', $weekStart->toDateString())
            ->first();
        $expected = MaterialFingerprint::forSeason(
            $this->sustainedAheadOfRacePace->forUser($user->id, $weekStart),
            $adaptation?->reason,
            $adaptation->deload ?? false,
        );
        if ($this->analysisService->shouldServeRuleBased($user)) {
            $this->analysisService->requestRuleBased(Season::class, $season->id, AnalysisType::PlanSeasonVoice, refillDone: false);

            return;
        }

        $stamped = Analysis::query()
            ->forSubject(Season::class, $season->id, AnalysisType::PlanSeasonVoice)
            ->value('content_fingerprint');

        $this->analysisService->request(
            Season::class,
            $season->id,
            AnalysisType::PlanSeasonVoice,
            invalidate: $stamped !== $expected,
        );
    }

    /**
     * The demo account's equivalent of {@see self::requestForCurrentWeek()}:
     * `plan:regenerate` deliberately skips demo for the real dispatch (see
     * `RegeneratePlanCommand`), but its Plan page still needs the season block
     * filled. Rule-based only, never the LLM (`requestRuleBased()`, the same
     * path the demo account's manual "Reread" already resolves through), and
     * `refillDone: false` so a row already filled on an earlier view is left
     * alone rather than rewritten on every page load.
     */
    public function ensureDemoFilled(User $user): void
    {
        $season = $this->currentSeason($user);
        if ($season !== null) {
            $this->analysisService->requestRuleBased(
                Season::class,
                $season->id,
                AnalysisType::PlanSeasonVoice,
                refillDone: false,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function seasonPayload(User $user): ?array
    {
        $season = $this->currentSeason($user);
        $seasonRow = $season === null
            ? null
            : Analysis::query()->forSubject(Season::class, $season->id, AnalysisType::PlanSeasonVoice)->first();

        return $seasonRow === null ? null : Analysis::toPayload(
            $seasonRow,
            AnalysisType::PlanSeasonVoice,
            Season::class,
            $season->id,
        );
    }

    private function currentSeason(User $user): ?Season
    {
        return $this->season->latest($user->id);
    }
}
