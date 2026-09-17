<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\Run\Plan\ClampNarrationContext;
use App\Services\Run\Plan\SustainedAheadOfRacePace;
use App\Services\Run\Plan\TrainingBaseline;
use App\Support\Cooldown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Actions\Run\Plan\ResolveSeasonAction;

/**
 * Requests fresh season narration for the current week and a day's read once
 * it has been run, reads both back for the Plan page, and rate-limits how
 * often {@see \App\Http\Controllers\PlanController::regenerate()} may run — a
 * manual regenerate re-narrates the season, a real LLM cost per click.
 *
 * A day's own read is requested only from {@see self::requestDayVoiceIfChanged()}
 * and {@see self::requestDayNarration()}, never from the week-wide requests
 * here: ahead-of-time day narration was cut in #939, since a day with no run
 * has nothing to read.
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
        return Analysis::query()
            ->forSubject(AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE, $user->id, AnalysisType::PlanClampVoice, $today->toDateString())
            ->where('status', AnalysisStatus::Done)
            ->value('content');
    }

    /**
     * Re-narrates one day's blurb when the day's own material has changed —
     * which, after {@see \App\Services\Run\Plan\ComplianceScorer::creditIfEarned()},
     * means the day just flipped to credited (or its intent verdict moved) and
     * the line should now read what happened. A day nothing has credited yet
     * asks for no read at all (#939: "no run, no section").
     *
     * Scoped to the single date rather than going through
     * {@see self::requestForCurrentWeek()}: that walks the whole week and would
     * re-request six days nothing touched. Returns whether anything was asked
     * for, so the caller can tell a real invalidation from a no-op.
     *
     * The gated counterpart to {@see self::requestDayNarration()}, which
     * invalidates unconditionally. That is right for a user edit, where the
     * athlete has just changed the day; it is wrong here, because this runs on
     * every ingested run and would re-bill each one.
     */
    public function requestDayVoiceIfChanged(User $user, Carbon $date): bool
    {
        $key = $date->toDateString();
        $session = $this->plannedSessionsFor($user, [$key])->first();
        if ($session === null || ! $session->status->isCredited()) {
            return false;
        }

        $longRunKm = $this->baseline->forUser($user, $date)['long_run_km'];
        $expected = MaterialFingerprint::forPlannedSession($session, $longRunKm);
        if ($expected === $this->stampedDayFingerprints($user, [$key])[$key]) {
            return false;
        }

        $this->analysisService->request(
            AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            $user->id,
            AnalysisType::PlanDayVoice,
            $key,
            invalidate: true,
        );

        return true;
    }

    /**
     * Asks for a line explaining today's readiness step-down, if there is one.
     *
     * Called from the two places that already compute a ceiling — the ingest
     * listener and the 00:01 briefing — and never from a render, so a GET never
     * bills. A run landing is what moves the ceiling, so the event that
     * invalidates this line is the event that regenerates it, and the line is
     * usually ready before the app is next opened.
     *
     * Requesting resolves the clamp rather than assuming one: an athlete whose
     * day already fits under the ceiling has nothing to explain, and a row
     * requested for a clamp that has since lifted would be one the job could
     * never fill.
     */
    public function requestClampVoice(User $user, Carbon $today): bool
    {
        if ($this->clampContext->forUserOn($user->id, $today) === null) {
            return false;
        }

        $this->analysisService->request(
            subjectOrType: AnalysisType::PLAN_CLAMP_VOICE_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::PlanClampVoice,
            discriminator: $today->toDateString(),
        );

        return true;
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
     * Requests narration for the current season. Ahead-of-time day narration
     * was cut (#939): a day's read is requested only once it has a run, from
     * {@see self::requestDayVoiceIfChanged()} right after it is credited, so
     * the Monday sweep and the manual regenerate button no longer touch
     * `plan_day_voice` at all.
     *
     * Season narration relies on AnalysisService's own idempotency: an
     * unchanged season's already-Done content is left alone rather than re-billed.
     */
    public function requestForCurrentWeek(User $user, Carbon $today): void
    {
        $this->requestWeek($user, $today);
    }

    /**
     * The brand-new account's one narration of its first week — the season
     * only, for the same reason {@see self::requestForCurrentWeek()} no
     * longer touches any day: a first week has no run in it yet.
     */
    public function requestForFirstWeek(User $user, Carbon $today): void
    {
        $this->requestWeek($user, $today);
    }

    /**
     * Requests the season row, invalidating only when
     * {@see SustainedAheadOfRacePace} has flipped since the row's last
     * fingerprint — everything else about a season's material is fixed at
     * creation. A never-fingerprinted Done row (predating this signal) counts
     * as changed, so every existing season re-narrates once rather than
     * silently carrying a blurb that never had a chance to mention it.
     */
    private function requestWeek(User $user, Carbon $today): void
    {
        $season = $this->currentSeason($user);
        if ($season === null) {
            return;
        }

        $expected = MaterialFingerprint::forSeason(
            $this->sustainedAheadOfRacePace->forUser($user->id, $today->copy()->startOfWeek(Carbon::MONDAY)),
        );
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
     * `RegeneratePlanCommand`), but its Plan page still needs every narration
     * block filled — the demo's whole point is a working, filled-out
     * experience. Rule-based only, never the LLM (`requestRuleBased()`, the
     * same path the demo account's manual "Reread" already resolves through),
     * and `refillDone: false` so a row already filled on an earlier view is
     * left alone rather than rewritten on every page load.
     *
     * A day only gets a read once it has a run credited on it (#939), the same
     * rule {@see self::requestDayVoiceIfChanged()} applies for a real athlete.
     */
    public function ensureDemoFilled(User $user, Carbon $today): void
    {
        $dates = $this->currentWeekDates($today);
        $sessionsByDate = $this->plannedSessionsFor($user, $dates)
            ->keyBy(fn (PlannedSession $session): string => $session->date->toDateString());

        foreach ($dates as $date) {
            $session = $sessionsByDate->get($date);
            if ($session === null || ! $session->status->isCredited()) {
                continue;
            }

            $this->analysisService->requestRuleBased(
                AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
                $user->id,
                AnalysisType::PlanDayVoice,
                $date,
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
     *
     * Invalidates unconditionally, because the edit IS the change. Anything
     * firing on a repeatable event wants {@see self::requestDayVoiceIfChanged()}
     * instead, which asks the fingerprint first.
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

    /**
     * A rule-based read for a past, credited day — never the LLM. Used by
     * `plan:regrade-season` to backfill a read for every already-graded day
     * of the current season in the same deploy-time step as the regrade
     * itself, so history phrases #946's verdict instead of the ahead-of-time
     * label it may have been narrated with before #939.
     *
     * `refillDone: true` deliberately overwrites whatever content a row
     * already carries, ahead-of-time or otherwise: history is backfilled
     * once, rule-based, and is never worth a further LLM call.
     */
    public function backfillRuleBasedRead(User $user, Carbon $date): void
    {
        $this->analysisService->requestRuleBased(
            AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            $user->id,
            AnalysisType::PlanDayVoice,
            $date->toDateString(),
        );
    }

    public function isWithinCurrentWeek(Carbon $date, Carbon $today): bool
    {
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        return ! $date->lt($weekStart) && ! $date->gt($weekStart->copy()->addDays(6));
    }

    /**
     * @return array{days: array<string, array<string, mixed>>, season: array<string, mixed>|null}
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

        $sessions = $this->plannedSessionsFor($user, $dates);
        $expected = self::fingerprintsFrom($sessions, $this->baseline->forUser($user, $today)['long_run_km']);
        $sessionsByDate = $sessions->keyBy(fn (PlannedSession $session): string => $session->date->toDateString());

        $days = [];
        foreach ($dates as $date) {
            // No run, no section (#939) — whether that's a day still ahead, an
            // eased day still speaking through its own clamp line, a missed
            // day, or an excused one, none of them has a read to show.
            $session = $sessionsByDate->get($date);
            if ($session === null || ! $session->status->isCredited()) {
                continue;
            }

            $row = $dayRows->get($date);
            if (self::isUnbacked($row, $expected[$date] ?? null)) {
                continue;
            }

            $days[$date] = Analysis::toPayload(
                $row,
                AnalysisType::PlanDayVoice,
                AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
                $user->id,
                $date,
            );
        }

        // A Season exists from the first /plan load, so without this the
        // season block promises a take nobody has queued — the same false
        // hope the day loop above skips.
        $season = $this->currentSeason($user);
        $seasonRow = $season === null
            ? null
            : Analysis::query()->forSubject(Season::class, $season->id, AnalysisType::PlanSeasonVoice)->first();
        $seasonPayload = $seasonRow === null ? null : Analysis::toPayload(
            $seasonRow,
            AnalysisType::PlanSeasonVoice,
            Season::class,
            $season->id,
        );

        return ['days' => $days, 'season' => $seasonPayload];
    }

    /**
     * Whether a day has nothing to show and nothing coming: either no row at
     * all, or a finished one whose content describes a plan the athlete has
     * since changed. `Analysis::toPayload(null, ...)` reports `Pending`, which
     * the UI draws as a skeleton — honest while a job is queued, false hope
     * when none is. A drifted `done` row is the same problem wearing content:
     * it would state a session that is no longer prescribed. Failed rows are
     * left alone; that empty state is a real fault worth surfacing.
     */
    private static function isUnbacked(?Analysis $row, ?string $expectedFingerprint): bool
    {
        if ($row === null) {
            return true;
        }

        return $row->status === AnalysisStatus::Done
            && $row->content_fingerprint !== null
            && $expectedFingerprint !== null
            && $row->content_fingerprint !== $expectedFingerprint;
    }

    /**
     * @param  list<string>  $dates
     * @return Collection<int, PlannedSession>
     */
    private function plannedSessionsFor(User $user, array $dates): Collection
    {
        return PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereIn('date', $dates)
            ->get();
    }

    /**
     * @param  Collection<int, PlannedSession>  $sessions
     * @return array<string, string>
     */
    private static function fingerprintsFrom(Collection $sessions, float $longRunKm): array
    {
        return $sessions
            ->mapWithKeys(fn (PlannedSession $session): array => [
                $session->date->toDateString() => MaterialFingerprint::forPlannedSession($session, $longRunKm),
            ])
            ->all();
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

    private function currentSeason(User $user): ?Season
    {
        return $this->season->latest($user->id);
    }
}
