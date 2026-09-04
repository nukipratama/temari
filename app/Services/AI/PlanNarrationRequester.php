<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\Run\Plan\TrainingBaseline;
use App\Support\Cooldown;
use Illuminate\Support\Carbon;

/**
 * Requests fresh day/week/season plan narration for the current week, reads
 * it back for the Plan page, and rate-limits how often
 * {@see \App\Http\Controllers\PlanController::regenerate()} may run — a
 * manual regenerate re-narrates up to 9 rows (7 days, the week, the
 * season), so it carries a real LLM cost per click.
 *
 * The regenerate cooldown is a dedicated key, not {@see \App\Models\AI\Analysis::cooldownKey()}
 * reused: every narration row's own completion unconditionally starts its
 * *own* (shorter, default) cooldown in {@see AnalysisService::markDone()}, so
 * reusing that same key here would have this class's longer window silently
 * overwritten within moments by the async job's own completion.
 */
final readonly class PlanNarrationRequester
{
    /**
     * How long a manual regenerate is rate-limited for. Longer than the
     * default {@see Cooldown::WINDOW_SECONDS} (15 min) used for a single
     * narration block's own "Reread": a full regenerate is a heavier action,
     * re-narrating the whole week at once rather than one block.
     */
    public const int REGENERATE_COOLDOWN_SECONDS = 3600;

    public function __construct(
        private AnalysisService $analysisService,
        private TrainingBaseline $baseline,
    ) {
    }

    /**
     * Seconds left before this user may regenerate again, or null if they may
     * regenerate now.
     */
    public function regenerateCooldownRemaining(User $user): ?int
    {
        return $this->regenerateCooldown($user)->remaining();
    }

    /**
     * Starts the regenerate cooldown immediately — not deferred to a
     * narration job's completion, which would leave a queue-latency window
     * where a second rapid click isn't yet blocked.
     */
    public function startRegenerateCooldown(User $user): void
    {
        $this->regenerateCooldown($user)->start();
    }

    private function regenerateCooldown(User $user): Cooldown
    {
        return new Cooldown("plan-regenerate:{$user->id}", self::REGENERATE_COOLDOWN_SECONDS);
    }

    /**
     * Requests narration for every day of the current week, the current
     * week's adaptation verdict (if regenerate has run at least once), and
     * the current season.
     *
     * Day and week narration re-bill **only where the material actually
     * changed**. This runs every Monday for every user, seven days at a time,
     * and the periodizer frequently rewrites a week into something that reads
     * identically — an unchanged session type, phase and prescribed distance
     * produce the same blurb, so re-narrating it buys nothing. Each row carries
     * a {@see MaterialFingerprint} of what it describes, stamped when it was
     * narrated; a row whose fingerprint still matches is left alone.
     *
     * **A row with no stored fingerprint is treated as changed**, unlike the
     * per-run equivalent in `DispatchPostRunAnalysis`, which treats an unstamped
     * row as unchanged so shipping it never mass-invalidates history. The
     * opposite is right here: only the rule-based paths leave the column null
     * (a cost-capped or content-filtered day), and those must stay eligible for
     * a real narration on the next sweep rather than keeping filler forever.
     *
     * Season narration relies on AnalysisService's own idempotency: an
     * unchanged season's already-Done content is left alone rather than re-billed.
     */
    public function requestForCurrentWeek(User $user, Carbon $today): void
    {
        $dates = $this->currentWeekDates($today);
        $longRunKm = $this->baseline->forUser($user, $today)['long_run_km'];
        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereIn('date', $dates)
            ->get()
            ->keyBy(fn (PlannedSession $session): string => $session->date->toDateString());
        $stamped = $this->stampedDayFingerprints($user, $dates);

        foreach ($dates as $date) {
            $session = $sessions->get($date);
            $expected = $session === null
                ? null
                : MaterialFingerprint::forPlannedSession($session, $longRunKm);

            $this->analysisService->request(
                AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
                $user->id,
                AnalysisType::PlanDayVoice,
                $date,
                invalidate: $expected === null || $stamped[$date] !== $expected,
            );
        }

        $adaptation = $this->currentWeekAdaptation($user, $today);
        if ($adaptation !== null) {
            $stampedWeek = Analysis::query()
                ->forSubject(PlanAdaptation::class, $adaptation->id, AnalysisType::PlanWeekVoice)
                ->value('content_fingerprint');

            $this->analysisService->request(
                PlanAdaptation::class,
                $adaptation->id,
                AnalysisType::PlanWeekVoice,
                invalidate: $stampedWeek !== MaterialFingerprint::forPlanAdaptation($adaptation),
            );
        }

        $season = $this->currentSeason($user);
        if ($season !== null) {
            $this->analysisService->request(
                Season::class,
                $season->id,
                AnalysisType::PlanSeasonVoice,
            );
        }
    }

    /**
     * The demo account's equivalent of {@see self::requestForCurrentWeek()}:
     * `plan:regenerate` deliberately skips demo for the real dispatch (see
     * `RegeneratePlanCommand`), but its Plan page still needs every narration
     * block filled — the demo's whole point is a working, filled-out
     * experience. Rule-based only, never the LLM (`requestRuleBased()`, the
     * same path the demo account's manual "Reread" already resolves through),
     * and `refillDone: false` so a row already filled on an earlier view is
     * left alone rather than rewritten on every page load.
     */
    public function ensureDemoFilled(User $user, Carbon $today): void
    {
        foreach ($this->currentWeekDates($today) as $date) {
            $this->analysisService->requestRuleBased(
                AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
                $user->id,
                AnalysisType::PlanDayVoice,
                $date,
                refillDone: false,
            );
        }

        $adaptation = $this->currentWeekAdaptation($user, $today);
        if ($adaptation !== null) {
            $this->analysisService->requestRuleBased(
                PlanAdaptation::class,
                $adaptation->id,
                AnalysisType::PlanWeekVoice,
                refillDone: false,
            );
        }

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
     * Re-narrates a single day — used when an edit
     * ({@see \App\Http\Controllers\PlanController::update()}) changes what a
     * day's blurb would need to say, so it never keeps describing a session
     * the athlete just skipped, blocked, or moved off of.
     */
    public function requestDayNarration(int $userId, Carbon $date): void
    {
        $this->analysisService->request(
            AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            $userId,
            AnalysisType::PlanDayVoice,
            $date->toDateString(),
            invalidate: true,
        );
    }

    public function isWithinCurrentWeek(Carbon $date, Carbon $today): bool
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        return ! $date->lt($weekStart) && ! $date->gt($weekStart->copy()->addDays(6));
    }

    /**
     * @return array{days: array<string, array<string, mixed>>, week: array<string, mixed>|null, season: array<string, mixed>|null}
     */
    public function payloadsForCurrentWeek(User $user, Carbon $today): array
    {
        $dates = $this->currentWeekDates($today);
        $dayRows = Analysis::query()
            ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::PlanDayVoice)
            ->whereIn('discriminator', $dates)
            ->get()
            ->keyBy('discriminator');

        $days = [];
        foreach ($dates as $date) {
            $days[$date] = Analysis::toPayload(
                $dayRows->get($date),
                AnalysisType::PlanDayVoice,
                AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
                $user->id,
                $date,
            );
        }

        $adaptation = $this->currentWeekAdaptation($user, $today);
        $week = $adaptation === null ? null : Analysis::toPayload(
            Analysis::query()->forSubject(PlanAdaptation::class, $adaptation->id, AnalysisType::PlanWeekVoice)->first(),
            AnalysisType::PlanWeekVoice,
            PlanAdaptation::class,
            $adaptation->id,
        );

        $season = $this->currentSeason($user);
        $seasonPayload = $season === null ? null : Analysis::toPayload(
            Analysis::query()->forSubject(Season::class, $season->id, AnalysisType::PlanSeasonVoice)->first(),
            AnalysisType::PlanSeasonVoice,
            Season::class,
            $season->id,
        );

        return ['days' => $days, 'week' => $week, 'season' => $seasonPayload];
    }

    /**
     * The fingerprint stamped on each day's row, keyed by date, with null for a
     * day that has no row or was never stamped.
     *
     * @param  list<string>  $dates
     * @return array<string, string|null>
     */
    private function stampedDayFingerprints(User $user, array $dates): array
    {
        $rows = Analysis::query()
            ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::PlanDayVoice)
            ->whereIn('discriminator', $dates)
            ->pluck('content_fingerprint', 'discriminator');

        return array_combine(
            $dates,
            array_map(static fn (string $date): ?string => $rows->get($date), $dates),
        );
    }

    /** @return list<string> */
    private function currentWeekDates(Carbon $today): array
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        return array_map(
            static fn (int $offset): string => $weekStart->copy()->addDays($offset)->toDateString(),
            range(0, 6),
        );
    }

    private function currentWeekAdaptation(User $user, Carbon $today): ?PlanAdaptation
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        return PlanAdaptation::query()
            ->where('user_id', $user->id)
            ->where('week_start', $weekStart->toDateString())
            ->first();
    }

    private function currentSeason(User $user): ?Season
    {
        return Season::query()->where('user_id', $user->id)->orderByDesc('starts_at')->first();
    }
}
