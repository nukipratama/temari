<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\AI\AnalyzePlanWeekVoiceJob;
use App\Models\AI\Analysis;
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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-31 08:00:00'); // a Monday
    $this->requester = app(PlanNarrationRequester::class);
});
afterEach(fn () => Carbon::setTestNow());

it('requests narration for every planned day of the current week', function (): void {
    $user = User::factory()->create();
    $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
    foreach (range(0, 6) as $offset) {
        PlannedSession::factory()->for($user)->create(['date' => $monday->copy()->addDays($offset)->toDateString()]);
    }

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 7);
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::PlanDayVoice)
        ->count())->toBe(7);
});

it('skips a day the plan prescribes nothing for, rather than requesting a narration with no material', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 1);
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::PlanDayVoice)
        ->pluck('discriminator')->all())->toBe([Carbon::today()->toDateString()]);
});

it('requests week narration only when a PlanAdaptation exists for the current week', function (): void {
    $user = User::factory()->create();
    $this->requester->requestForCurrentWeek($user, Carbon::today());
    Bus::assertNotDispatched(AnalyzePlanWeekVoiceJob::class);

    $adaptation = PlanAdaptation::factory()->for($user)->create(['week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)]);
    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertDispatched(
        AnalyzePlanWeekVoiceJob::class,
        fn (AnalyzePlanWeekVoiceJob $job): bool => Analysis::query()->find($job->analysisId)?->subject_id === $adaptation->id,
    );
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

it('invalidates an already-Done day row on the next request, but leaves season alone', function (): void {
    $user = User::factory()->create();
    $today = Carbon::today()->toDateString();
    PlannedSession::factory()->for($user)->create(['date' => $today]);
    Analysis::factory()->done('yesterday\'s content')->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => $today,
    ]);
    $season = Season::factory()->for($user)->create();
    Analysis::factory()->done('season content')->create([
        'subject_type' => Season::class,
        'subject_id' => $season->id,
        'analysis_type' => AnalysisType::PlanSeasonVoice,
        'discriminator' => null,
    ]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    $dayRow = Analysis::query()
        ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('discriminator', $today)
        ->firstOrFail();
    $seasonRow = Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->firstOrFail();

    expect($dayRow->status)->toBe(AnalysisStatus::Queued) // invalidated, re-dispatched
        ->and($seasonRow->status)->toBe(AnalysisStatus::Done) // left alone
        ->and($seasonRow->content)->toBe('season content');
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
            ->and($payloads['week'])->toBeNull()
            ->and($payloads['season'])->toBeNull();
    });

    it('returns the real content once rows exist', function (): void {
        $user = User::factory()->create();
        $today = Carbon::today()->toDateString();
        Analysis::factory()->done('long run today')->create([
            'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::PlanDayVoice,
            'discriminator' => $today,
        ]);
        $adaptation = PlanAdaptation::factory()->for($user)->create(['week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)]);
        Analysis::factory()->done('steady week')->create([
            'subject_type' => PlanAdaptation::class,
            'subject_id' => $adaptation->id,
            'analysis_type' => AnalysisType::PlanWeekVoice,
            'discriminator' => null,
        ]);

        $payloads = $this->requester->payloadsForCurrentWeek($user, Carbon::today());

        expect($payloads['days'][$today]['content'])->toBe('long run today')
            ->and($payloads['week']['content'])->toBe('steady week');
    });
});

describe('ensureDemoFilled', function (): void {
    it('fills every block rule-based, without dispatching any job', function (): void {
        $user = User::factory()->create(['is_demo' => true]);
        PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString(), 'session_type' => 'easy']);
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

it('leaves an unchanged day alone instead of re-billing it every Monday', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    $row = stampedDay($user, $session);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->content)->toBe('already narrated')
        // The other six days have no planned session, so they are skipped.
        ->and(Bus::dispatched(AnalyzePlanDayVoiceJob::class))->toHaveCount(0);
});

it('re-narrates a day whose prescribed session changed', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Easy,
    ]);
    $row = stampedDay($user, $session);

    // The periodizer rewrote the week: this day is now a tempo session, so the
    // stored blurb describes something the athlete is no longer being asked to do.
    $session->update(['session_type' => SessionType::Tempo]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued)
        ->and(Bus::dispatched(AnalyzePlanDayVoiceJob::class))->toHaveCount(1);
});

it('re-narrates a day the athlete has since excused themselves from', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'skipped' => false,
    ]);
    $row = stampedDay($user, $session);

    $session->update(['skipped' => true]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued);
});

it('re-narrates a day left rule-based, since a filler line is not a narration of the material', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    // A cost-capped day: marked Done with filler and never stamped.
    $row = Analysis::factory()->done('filler line')->create([
        'subject_type' => AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::PlanDayVoice,
        'discriminator' => Carbon::today()->toDateString(),
        'content_fingerprint' => null,
    ]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued);
});

it('leaves an unchanged week adaptation alone', function (): void {
    $user = User::factory()->create();
    $adaptation = PlanAdaptation::factory()->for($user)->create([
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
    ]);
    $row = Analysis::factory()->done('week narrated')->create([
        'subject_type' => PlanAdaptation::class,
        'subject_id' => $adaptation->id,
        'analysis_type' => AnalysisType::PlanWeekVoice,
        'discriminator' => null,
        'content_fingerprint' => MaterialFingerprint::forPlanAdaptation($adaptation),
    ]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->content)->toBe('week narrated');
    Bus::assertNotDispatched(AnalyzePlanWeekVoiceJob::class);
});

it('re-narrates a week adaptation whose verdict changed', function (): void {
    $user = User::factory()->create();
    $adaptation = PlanAdaptation::factory()->for($user)->create([
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'deload' => false,
    ]);
    $row = Analysis::factory()->done('week narrated')->create([
        'subject_type' => PlanAdaptation::class,
        'subject_id' => $adaptation->id,
        'analysis_type' => AnalysisType::PlanWeekVoice,
        'discriminator' => null,
        'content_fingerprint' => MaterialFingerprint::forPlanAdaptation($adaptation),
    ]);

    $adaptation->update(['deload' => true]);

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzePlanWeekVoiceJob::class);
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
        PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);

        // Cooled down, so the guarded call is a no-op...
        $this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today());
        Analysis::query()->where('subject_id', $user->id)->delete();
        expect($this->requester->requestForCurrentWeekUnlessCoolingDown($user, Carbon::today()))->toBeFalse()
            ->and(Analysis::query()->where('subject_id', $user->id)->count())->toBe(0);

        // ...while the direct path the button, the weekly job and onboarding
        // use still writes rows.
        $this->requester->requestForCurrentWeek($user, Carbon::today());

        expect(Analysis::query()->where('subject_id', $user->id)->count())->toBeGreaterThan(0);
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
        PlannedSession::factory()->for($user)->create(['date' => $today]);
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
    it('narrates the day, week and season blocks of a brand-new account', function (): void {
        $user = User::factory()->create();
        PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
        PlanAdaptation::factory()->for($user)->create(['week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)]);
        Season::factory()->for($user)->create();

        $this->requester->requestForFirstWeek($user, Carbon::today());

        Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 1);
        Bus::assertDispatched(AnalyzePlanWeekVoiceJob::class);
        Bus::assertDispatched(AnalyzePlanSeasonVoiceJob::class);
    });

    /**
     * Onboarding and the connect chain both call this, and the second one to
     * arrive must cost nothing — even where the material has since drifted.
     */
    it('never invalidates a finished row, so the second racer bills nothing', function (): void {
        $user = User::factory()->create();
        $session = PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->toDateString(),
            'session_type' => SessionType::Easy,
        ]);
        $row = stampedDay($user, $session);
        $session->update(['session_type' => SessionType::Tempo]);

        $this->requester->requestForFirstWeek($user, Carbon::today());

        expect($row->fresh()->status)->toBe(AnalysisStatus::Done);
        Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    });
});
