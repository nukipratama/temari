<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Models\AI\Analysis;
use App\Models\Feedback;
use App\Services\AI\ServedBy;
use App\Services\AI\AnalysisService;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Enums\SessionType;
use App\Models\User;
use App\Support\Cooldown;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use App\Enums\PlannedSessionStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-31 08:00:00'); // a Monday
    $this->requester = app(PlanNarrationRequester::class);
});
afterEach(fn () => Carbon::setTestNow());

/**
 * #939: ahead-of-time day narration was cut entirely. A day gets a read only
 * once it has a run credited on it, requested separately by
 * {@see PlanNarrationRequester::requestDayVoiceIfChanged()} — the week sweep
 * never touches `plan_day_voice`, whatever the week's own planned sessions are.
 */
it('requests no day narration at all for the current week, whatever the week holds', function (): void {
    $user = User::factory()->create();
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    foreach (range(0, 6) as $offset) {
        PlannedSession::factory()->for($user)->create(['date' => $monday->copy()->addDays($offset)->toDateString()]);
    }

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::PlanDayVoice)
        ->count())->toBe(0);
});

/**
 * #947: ahead-of-time week narration was cut entirely. A PlanAdaptation
 * existing for the current week must never mint a `plan_week_voice` row —
 * queried raw since the type is no longer a live AnalysisType case.
 */
it('never requests plan_week_voice narration, even when a PlanAdaptation exists for the current week', function (): void {
    $user = User::factory()->create();
    PlanAdaptation::factory()->for($user)->create(['week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect(DB::table('ai_analyses')->where('analysis_type', 'plan_week_voice')->count())->toBe(0);
});

it('requests season narration only when a Season exists', function (): void {
    $user = User::factory()->create();
    $this->requester->requestForCurrentWeek($user, Carbon::today());
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);

    $season = Season::factory()->for($user)->create();
    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertDispatched(
        AnalyzePlanSeasonVoiceJob::class,
        fn (AnalyzePlanSeasonVoiceJob $job): bool => Analysis::query()->find($job->analysisId)?->subject_id === $season->id,
    );
});

it('leaves an already-Done day row untouched, and leaves an unchanged season row alone', function (): void {
    $user = User::factory()->create();
    $today = Carbon::today()->toDateString();
    PlannedSession::factory()->for($user)->create(['date' => $today, 'status' => PlannedSessionStatus::Done]);
    Analysis::factory()->done('yesterday\'s content')->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => $today,
    ]);
    $season = Season::factory()->for($user)->create();
    // Stamped with the fingerprint of the current (not sustained-ahead) state,
    // so this row is genuinely unchanged rather than merely never-fingerprinted.
    Analysis::factory()->done('season content')->create([
        'subject_type' => Season::class,
        'subject_id' => $season->id,
        'analysis_type' => AnalysisType::PlanSeasonVoice,
        'discriminator' => null,
        'content_fingerprint' => MaterialFingerprint::forSeason(false),
    ]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    $dayRow = Analysis::query()
        ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('discriminator', $today)
        ->firstOrFail();
    $seasonRow = Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->firstOrFail();

    expect($dayRow->status)->toBe(AnalysisStatus::Done) // never touched by the week sweep
        ->and($dayRow->content)->toBe('yesterday\'s content')
        ->and($seasonRow->status)->toBe(AnalysisStatus::Done) // idempotent: AnalysisService leaves it alone
        ->and($seasonRow->content)->toBe('season content');
    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
});

/**
 * #933: a Done season row from before the sustained-ahead signal existed
 * carries no fingerprint at all. That reads as changed (same precedent as
 * the trend-read fingerprint's own introduction), so every season re-narrates
 * once rather than silently never mentioning a goal already held for weeks.
 */
it('re-narrates an already-Done season row once, the first time it carries no fingerprint', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    Analysis::factory()->done('season content')->create([
        'subject_type' => Season::class,
        'subject_id' => $season->id,
        'analysis_type' => AnalysisType::PlanSeasonVoice,
        'discriminator' => null,
    ]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertDispatched(AnalyzePlanSeasonVoiceJob::class);
});

/**
 * #933: two consecutive AheadOfRacePace weeks flip the signal, which must
 * invalidate an already-narrated, already-fingerprinted season row exactly
 * once -- never on the way in, and never a second time once it has re-narrated.
 */
it('invalidates the season row exactly once when the sustained-ahead signal flips', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    Analysis::factory()->done('steady arc')->create([
        'subject_type' => Season::class,
        'subject_id' => $season->id,
        'analysis_type' => AnalysisType::PlanSeasonVoice,
        'discriminator' => null,
        'content_fingerprint' => MaterialFingerprint::forSeason(false, AdaptationReason::AheadOfRacePace, false),
    ]);

    // Not sustained yet: one ahead week, no flip.
    PlanAdaptation::factory()->for($user)->create([
        'week_start' => $weekStart->toDateString(),
        'reason' => AdaptationReason::AheadOfRacePace,
    ]);
    $this->requester->requestForCurrentWeek($user, Carbon::today());
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);

    // Sustained now: the second consecutive ahead week flips the fingerprint.
    PlanAdaptation::factory()->for($user)->create([
        'week_start' => $weekStart->copy()->subWeek()->toDateString(),
        'reason' => AdaptationReason::AheadOfRacePace,
    ]);
    $this->requester->requestForCurrentWeek($user, Carbon::today());
    Bus::assertDispatchedTimes(AnalyzePlanSeasonVoiceJob::class, 1);
});

it('invalidates the season row when the current week adaptation changes', function (): void {
    $user = User::factory()->create();
    $season = Season::factory()->for($user)->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    PlanAdaptation::factory()->for($user)->create([
        'week_start' => $weekStart->toDateString(),
        'reason' => AdaptationReason::Steady,
        'deload' => false,
    ]);
    Analysis::factory()->done('steady arc')->create([
        'subject_type' => Season::class,
        'subject_id' => $season->id,
        'analysis_type' => AnalysisType::PlanSeasonVoice,
        'discriminator' => null,
        'content_fingerprint' => MaterialFingerprint::forSeason(false, AdaptationReason::Steady, false),
    ]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);

    PlanAdaptation::query()
        ->where('user_id', $user->id)
        ->where('week_start', $weekStart->toDateString())
        ->update(['reason' => AdaptationReason::MissedStimulus->value]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());
    Bus::assertDispatchedTimes(AnalyzePlanSeasonVoiceJob::class, 1);
});

it('re-narrates a single day via requestDayNarration', function (): void {
    $user = User::factory()->create();

    $this->requester->requestDayNarration($user->id, Carbon::today());

    Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 1);
});

describe('regenerate cooldown', function (): void {
    it('reports no cooldown before one is started', function (): void {
        $user = User::factory()->create();

        expect($this->requester->regenerateCooldownRemaining($user))->toBeNull();
    });

    it('reports a cooldown once started, scoped per user', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->requester->startRegenerateCooldown($user);

        expect($this->requester->regenerateCooldownRemaining($user))
            ->toBeInt()
            ->toBeGreaterThan(0)
            ->and($this->requester->regenerateCooldownRemaining($otherUser))->toBeNull();
    });
});

describe('isWithinCurrentWeek', function (): void {
    it('accepts every day inside the Monday-Sunday window and rejects the days just outside it', function (): void {
        $today = Carbon::today(); // Monday 2026-08-31
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        expect($this->requester->isWithinCurrentWeek($weekStart->copy(), $today))->toBeTrue()
            ->and($this->requester->isWithinCurrentWeek($weekStart->copy()->addDays(6), $today))->toBeTrue()
            ->and($this->requester->isWithinCurrentWeek($weekStart->copy()->subDay(), $today))->toBeFalse()
            ->and($this->requester->isWithinCurrentWeek($weekStart->copy()->addDays(7), $today))->toBeFalse();
    });
});

describe('payloadsForCurrentWeek', function (): void {
    /** #947: the Plan payload carries no week narration at all any more. */
    it('never carries a week key', function (): void {
        $user = User::factory()->create();
        PlanAdaptation::factory()->for($user)->create(['week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today()))
            ->not->toHaveKey('week');
    });

    /**
     * `Analysis::toPayload(null, ...)` reports Pending, which the UI draws as a
     * skeleton. That's honest while a job is queued and false hope when none
     * is — a day with no row has nothing coming, so it is omitted and the card
     * simply shows no take.
     */
    it('omits a day with no row at all rather than promising one is coming', function (): void {
        $user = User::factory()->create();

        $payloads = $this->requester->payloadsForCurrentWeek($user, Carbon::today());

        expect($payloads['days'])->toBe([])
            ->and($payloads['season'])->toBeNull();
    });

    it('omits the season take when its row exists but no take has been queued', function (): void {
        // A Season exists from the first /plan load, so a brand-new athlete
        // has one long before anything has narrated it.
        $user = User::factory()->create();
        Season::factory()->for($user)->create([
            'starts_at' => Carbon::today()->subWeek(),
            'ends_at' => Carbon::today()->addWeeks(8),
        ]);

        $payloads = $this->requester->payloadsForCurrentWeek($user, Carbon::today());

        expect($payloads['season'])->toBeNull();
    });

    it('returns the real content once rows exist for a credited day', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        PlannedSession::factory()->for($user)->create(['date' => $today, 'status' => PlannedSessionStatus::Done]);
        Analysis::factory()->done('long run today')->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
        ]);

        $payloads = $this->requester->payloadsForCurrentWeek($user, Carbon::today());

        expect($payloads['days'][$today]['content'])->toBe('long run today');
    });

    /** #939: no run, no section — a day still ahead shows no read, even with a stale row sitting under it. */
    it('omits a day that has a row but has not been credited', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        PlannedSession::factory()->for($user)->create(['date' => $today, 'status' => PlannedSessionStatus::Planned]);
        Analysis::factory()->done('tempo work today.')->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
        ]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'])->toBe([]);
    });

    /** Before credit an eased day speaks through its clamp line, never a blurb written for the session it replaced. */
    it('holds back the day take of an eased day not yet credited', function (): void {
        $user = User::factory()->create();
        $session = PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->toDateString(),
            'session_type' => SessionType::Tempo,
            'status' => PlannedSessionStatus::Planned,
        ]);
        stampedDay($user, $session);
        $session->update(['clamped_km' => 3.6]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'])->toBe([]);
    });

    it('holds back the day take of a rest-clamped day not yet credited', function (): void {
        $user = User::factory()->create();
        $session = PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->toDateString(),
            'session_type' => SessionType::Long,
        ]);
        stampedDay($user, $session);
        $session->update(['rest_clamped_at' => Carbon::now()]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'])->toBe([]);
    });

    it('hands an eased day back to its own take once it is credited', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        $session = PlannedSession::factory()->for($user)->create([
            'date' => $today,
            'session_type' => SessionType::Tempo,
            'clamped_km' => 3.6,
            'status' => PlannedSessionStatus::Done,
        ]);
        stampedDay($user, $session);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'][$today]['content'])->toBe('already narrated');
    });
});

describe('ensureDemoFilled', function (): void {
    it('fills every block rule-based, without dispatching any job', function (): void {
        $user = User::factory()->create(['is_demo' => true]);
        PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->toDateString(),
            'session_type' => 'easy',
            'status' => PlannedSessionStatus::Done,
        ]);
        $season = Season::factory()->for($user)->create();

        $this->requester->ensureDemoFilled($user, Carbon::today());

        Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
        Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);

        $today = Carbon::today()->toDateString();
        $dayRow = Analysis::query()
            ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('discriminator', $today)
            ->firstOrFail();
        $seasonRow = Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->firstOrFail();

        expect($dayRow->status)->toBe(AnalysisStatus::Done)
            ->and($dayRow->content)->not->toBeNull()
            ->and($seasonRow->status)->toBe(AnalysisStatus::Done);
    });

    it('leaves an already-filled row alone on a second call', function (): void {
        $user = User::factory()->create(['is_demo' => true]);
        $today = Carbon::today()->toDateString();
        PlannedSession::factory()->for($user)->create(['date' => $today, 'status' => PlannedSessionStatus::Done]);
        Analysis::factory()->done('original demo content')->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
        ]);

        $this->requester->ensureDemoFilled($user, Carbon::today());

        $dayRow = Analysis::query()
            ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('discriminator', $today)
            ->firstOrFail();

        expect($dayRow->content)->toBe('original demo content');
    });

    /** #939: no run, no section — the demo's Plan page shows no read for a day still ahead either. */
    it('fills no day that has not been credited', function (): void {
        $user = User::factory()->create(['is_demo' => true]);
        PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->toDateString(),
            'status' => PlannedSessionStatus::Planned,
        ]);

        $this->requester->ensureDemoFilled($user, Carbon::today());

        expect(Analysis::query()
            ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->exists())->toBeFalse();
    });
});

/**
 * A Done day row already stamped with the fingerprint of the session it
 * describes — the shape the Monday sweep must recognise as unchanged.
 */
function stampedDay(User $user, PlannedSession $session): Analysis
{
    $longRunKm = app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_km'];

    return Analysis::factory()->done('already narrated')->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => $session->date->toDateString(),
        'content_fingerprint' => MaterialFingerprint::forPlannedSession($session, $longRunKm),
    ]);
}

/**
 * #939: the week sweep's fingerprint-diffing behaviour moved onto
 * {@see PlanNarrationRequester::requestDayVoiceIfChanged()} (tested in its own
 * describe block below) — `requestForCurrentWeek()` never re-narrates a day
 * any more, changed, excused, or filler alike.
 */
it('never re-narrates any day from the week sweep, whatever moved under it', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Easy,
    ]);
    $row = stampedDay($user, $session);

    // The periodizer rewrote the week: this day is now a tempo session, so a
    // stale blurb describing it would previously have been the trigger.
    $session->update(['session_type' => SessionType::Tempo]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->content)->toBe('already narrated');
    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
});

describe('requestForCurrentWeekUnlessCoolingDown', function (): void {
    it('requests the first time and refuses inside the window', function (): void {
        $user = User::factory()->create();

        expect($this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today()))->toBeTrue()
            ->and($this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today()))->toBeFalse();
    });

    /**
     * The guard is on spend, not on correctness — the manual button and the
     * weekly job call requestForCurrentWeek() directly, onboarding and the
     * connect chain call requestForFirstWeek(), and all four are deliberately
     * unaffected by it.
     */
    it('does not block the uncooled request path', function (): void {
        $user = User::factory()->create();
        $season = Season::factory()->for($user)->create();
        $seasonRows = fn () => Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->count();

        // The first call is cooled down, so a second guarded call is a no-op...
        $this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today());
        Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->delete();
        expect($this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today()))->toBeFalse()
            ->and($seasonRows())->toBe(0);

        // ...while the direct path the button, the weekly job and onboarding
        // use still writes the season row.
        $this->requester->requestForCurrentWeek($user, Carbon::today());

        expect($seasonRows())->toBeGreaterThan(0);
    });

    it('lets the window lapse', function (): void {
        $user = User::factory()->create();
        $this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today());

        Carbon::setTestNow(Carbon::now()->addSeconds(Cooldown::PLAN_NARRATION_WINDOW_SECONDS + 1));

        expect($this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today()))->toBeTrue();
    });
});

describe('stale plan-day takes', function (): void {
    /**
     * The settings-change cooldown deliberately leaves a finished blurb in
     * place rather than re-billing it. That blurb describes the plan the
     * athlete had before the change, so it must not be shown — the whole point
     * of the advisory-clamp work was that the voice never contradicts the card.
     */
    it('hides a finished take whose plan has changed under it', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        PlannedSession::factory()->for($user)->create([
            'date' => $today,
            'session_type' => SessionType::Long,
            'status' => PlannedSessionStatus::Done,
        ]);
        Analysis::factory()->done('long run today')->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
            'content_fingerprint' => str_repeat('0', 40), // a digest from the plan they used to have
        ]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'])
            ->not->toHaveKey($today);
    });

    it('keeps a finished take whose plan still matches it', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        $session = PlannedSession::factory()->for($user)->create([
            'date' => $today,
            'session_type' => SessionType::Long,
            'status' => PlannedSessionStatus::Done,
        ]);
        $longRunKm = app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_km'];
        Analysis::factory()->done('long run today')->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
            'content_fingerprint' => MaterialFingerprint::forPlannedSession($session, $longRunKm),
        ]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'][$today]['content'])
            ->toBe('long run today');
    });

    /**
     * A rule-based fill (cost ceiling, content filter, or the demo account)
     * never stamps a fingerprint — that null is a deliberate "eligible for a
     * real narration later" marker, not a claim of staleness, so it must not
     * be treated as drift and hidden.
     */
    it('keeps a finished take with no stamped fingerprint', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        PlannedSession::factory()->for($user)->create([
            'date' => $today,
            'session_type' => SessionType::Long,
            'status' => PlannedSessionStatus::Done,
        ]);
        Analysis::factory()->done('rule-based take')->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
            'content_fingerprint' => null,
        ]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'][$today]['content'])
            ->toBe('rule-based take');
    });

    /** A queued job is real work, so the skeleton above it is a promise kept. */
    it('keeps a pending take, drifted or not, because a job is coming', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        PlannedSession::factory()->for($user)->create(['date' => $today, 'status' => PlannedSessionStatus::Done]);
        Analysis::factory()->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
            'status' => AnalysisStatus::Pending,
            'content_fingerprint' => str_repeat('0', 40),
        ]);

        expect($this->requester->payloadsForCurrentWeek($user, Carbon::today())['days'])
            ->toHaveKey($today);
    });
});

describe('requestForFirstWeek', function (): void {
    /**
     * #939: a brand-new account's first week has no run in it yet, so only
     * the season narrates — a day's own read waits for
     * {@see PlanNarrationRequester::requestDayVoiceIfChanged()}.
     */
    it('narrates only the season block of a brand-new account, and never plan_week_voice', function (): void {
        $user = User::factory()->create();
        PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
        PlanAdaptation::factory()->for($user)->create(['week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)]);
        Season::factory()->for($user)->create();

        $this->requester->requestForFirstWeek($user, Carbon::today());

        Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
        Bus::assertDispatched(AnalyzePlanSeasonVoiceJob::class);
        expect(DB::table('ai_analyses')->where('analysis_type', 'plan_week_voice')->count())->toBe(0);
    });
});

describe('requestDayVoiceIfChanged', function (): void {
    /** The whole point: it runs on every ingested run, so it must not re-bill. */
    it('asks for nothing when the day material has not moved', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today();
        $session = PlannedSession::factory()->for($user)->create(['date' => $today->toDateString()]);
        stampedDay($user, $session);

        expect($this->requester->requestDayVoiceIfChanged($user, $today))->toBeFalse();
        Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    });

    /** Crediting the day changes its digest, which is what earns the one re-narration. */
    it('re-narrates once when the day flips to credited', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today();
        $session = PlannedSession::factory()->for($user)->create([
            'date' => $today->toDateString(),
            'status' => PlannedSessionStatus::Planned,
        ]);
        stampedDay($user, $session);

        $session->forceFill(['status' => PlannedSessionStatus::Done])->save();

        expect($this->requester->requestDayVoiceIfChanged($user, $today->copy()))->toBeTrue();
        Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 1);
    });

    /** Crediting an eased day earns its one re-narration, and that replacement retires the flag on the old text. */
    it('re-narrates an eased day once it is credited, superseding the flag on the text it replaces', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today();
        $session = PlannedSession::factory()->for($user)->create([
            'date' => $today->toDateString(),
            'session_type' => SessionType::Tempo,
            'clamped_km' => 3.6,
            'status' => PlannedSessionStatus::Planned,
        ]);
        $row = stampedDay($user, $session);
        $flag = Feedback::factory()->onNarration($row->id)->create();

        $session->forceFill(['status' => PlannedSessionStatus::Done])->save();

        expect($this->requester->requestDayVoiceIfChanged($user, $today->copy()))->toBeTrue();
        Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 1);

        app(AnalysisService::class)->markDone($row->fresh(), 'an easy 3.6, done.', ServedBy::Llm);

        expect($flag->fresh()->superseded_at)->not->toBeNull();
    });

    it('asks for nothing on a date the plan does not cover', function (): void {
        $user = User::factory()->create();

        expect($this->requester->requestDayVoiceIfChanged($user, Carbon::today()))->toBeFalse();
        Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    });
});
