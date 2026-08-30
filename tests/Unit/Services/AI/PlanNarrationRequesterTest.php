<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\AI\AnalyzePlanWeekVoiceJob;
use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\PlanNarrationRequester;
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

it('requests narration for all 7 days of the current week', function (): void {
    $user = User::factory()->create();

    $this->requester->requestForCurrentWeek($user, Carbon::today());

    Bus::assertDispatchedTimes(AnalyzePlanDayVoiceJob::class, 7);
    expect(Analysis::query()
        ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::PlanDayVoice)
        ->count())->toBe(7);
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
    it('returns a Pending-shaped placeholder for every day, and null week/season, before anything exists', function (): void {
        $user = User::factory()->create();

        $payloads = $this->requester->payloadsForCurrentWeek($user, Carbon::today());

        expect($payloads['days'])->toHaveCount(7)
            ->and(collect($payloads['days'])->every(fn (array $p): bool => $p['status'] === AnalysisStatus::Pending->value))->toBeTrue()
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
