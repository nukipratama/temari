<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Jobs\AI\AnalyzePlanClampVoiceJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Plan\ClampNarrationContext;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Models\AI\Analysis;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-31 08:00:00'); // a Monday
    $this->requester = app(PlanNarrationRequester::class);
});
afterEach(fn () => Carbon::setTestNow());

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

    Bus::assertDispatched(fn (AnalyzePlanSeasonVoiceJob $job): bool => Analysis::query()->find($job->analysisId)?->subject_id === $season->id);
});

it('leaves an unchanged season row alone', function (): void {
    $user = User::factory()->create();
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

    $seasonRow = Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->firstOrFail();

    expect($seasonRow->status)->toBe(AnalysisStatus::Done) // idempotent: AnalysisService leaves it alone
        ->and($seasonRow->content)->toBe('season content');
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

it('serves the season rule-based for the demo account, dispatching nothing', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    $season = Season::factory()->for($user)->create();

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
    expect(Analysis::query()->forSubject(Season::class, $season->id, AnalysisType::PlanSeasonVoice)->firstOrFail()->status)
        ->toBe(AnalysisStatus::Done);
});

describe('requestClampVoice', function (): void {
    function tiredUserWithClampedDay(): User
    {
        $user = User::factory()->create();
        seedDemandingRunYesterday($user);
        PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->toDateString(),
            'session_type' => SessionType::Interval,
        ]);

        return $user;
    }

    it('asks for nothing when the day has no clamp', function (): void {
        $user = User::factory()->create();

        expect($this->requester->requestClampVoice($user, Carbon::today()))->toBeFalse();
        Bus::assertNotDispatched(AnalyzePlanClampVoiceJob::class);
    });

    it('regenerates only when the clamp it explains changed', function (): void {
        $user = tiredUserWithClampedDay();

        $this->requester->requestClampVoice($user, Carbon::today());
        Bus::assertDispatchedTimes(AnalyzePlanClampVoiceJob::class, 1);

        $context = app(ClampNarrationContext::class)->forUserOn($user->id, Carbon::today());
        $row = Analysis::query()->where('analysis_type', AnalysisType::PlanClampVoice)->firstOrFail();
        app(AnalysisService::class)->markDone(
            $row,
            'eased.',
            ServedBy::Llm,
            fingerprint: MaterialFingerprint::forClamp($context['ceiling'], $context['clamped_to'], $context['has_run_today'], $context['readiness_reasons']),
        );

        Bus::fake();
        $this->requester->requestClampVoice($user, Carbon::today());
        Bus::assertNotDispatched(AnalyzePlanClampVoiceJob::class);

        ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
            'start_date_local' => Carbon::today()->setHour(7),
        ]);
        $this->requester->requestClampVoice($user, Carbon::today());
        Bus::assertDispatchedTimes(AnalyzePlanClampVoiceJob::class, 1);
    });
});

describe('seasonPayload', function (): void {
    /**
     * `Analysis::toPayload(null, ...)` reports Pending, which the UI draws as a
     * skeleton. That's honest while a job is queued and false hope when none
     * is, so a season with no row is omitted.
     */
    it('omits the season take when no Season exists', function (): void {
        expect($this->requester->seasonPayload(User::factory()->create()))->toBeNull();
    });

    it('omits the season take when its row exists but no take has been queued', function (): void {
        // A Season exists from the first /plan load, so a brand-new athlete
        // has one long before anything has narrated it.
        $user = User::factory()->create();
        Season::factory()->for($user)->create([
            'starts_at' => Carbon::today()->subWeek(),
            'ends_at' => Carbon::today()->addWeeks(8),
        ]);

        expect($this->requester->seasonPayload($user))->toBeNull();
    });

    it('returns the real content once the season row exists', function (): void {
        $user = User::factory()->create();
        $season = Season::factory()->for($user)->create();
        Analysis::factory()->done('season content')->create([
            'subject_type' => Season::class,
            'subject_id' => $season->id,
            'analysis_type' => AnalysisType::PlanSeasonVoice,
            'discriminator' => null,
        ]);

        expect($this->requester->seasonPayload($user)['content'] ?? null)->toBe('season content');
    });
});

describe('ensureDemoFilled', function (): void {
    it('fills the season block rule-based, without dispatching any job', function (): void {
        $user = User::factory()->create(['is_demo' => true]);
        $season = Season::factory()->for($user)->create();

        $this->requester->ensureDemoFilled($user);

        Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
        $seasonRow = Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->firstOrFail();

        expect($seasonRow->status)->toBe(AnalysisStatus::Done)
            ->and($seasonRow->content)->not->toBeNull();
    });

    it('leaves an already-filled row alone on a second call', function (): void {
        $user = User::factory()->create(['is_demo' => true]);
        $season = Season::factory()->for($user)->create();
        Analysis::factory()->done('original demo content')->create([
            'subject_type' => Season::class,
            'subject_id' => $season->id,
            'analysis_type' => AnalysisType::PlanSeasonVoice,
            'discriminator' => null,
        ]);

        $this->requester->ensureDemoFilled($user);

        expect(Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->firstOrFail()->content)
            ->toBe('original demo content');
    });
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

describe('requestForFirstWeek', function (): void {
    it('narrates only the season block of a brand-new account, and never plan_week_voice', function (): void {
        $user = User::factory()->create();
        PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
        PlanAdaptation::factory()->for($user)->create(['week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)]);
        Season::factory()->for($user)->create();

        $this->requester->requestForFirstWeek($user, Carbon::today());

        Bus::assertDispatched(AnalyzePlanSeasonVoiceJob::class);
        expect(DB::table('ai_analyses')->where('analysis_type', 'plan_week_voice')->count())->toBe(0);
    });
});
