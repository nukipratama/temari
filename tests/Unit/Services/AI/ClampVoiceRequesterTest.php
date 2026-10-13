<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Jobs\AI\AnalyzePlanClampVoiceJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\ClampVoiceRequester;
use App\Services\AI\MaterialFingerprint;
use App\Services\AI\ServedBy;
use App\Services\Run\Plan\ClampNarrationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-31 08:00:00'); // a Monday
    $this->requester = app(ClampVoiceRequester::class);
});
afterEach(fn () => Carbon::setTestNow());

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

    expect($this->requester->request($user, Carbon::today()))->toBeFalse();
    Bus::assertNotDispatched(AnalyzePlanClampVoiceJob::class);
});

it('regenerates only when the clamp it explains changed', function (): void {
    $user = tiredUserWithClampedDay();

    $this->requester->request($user, Carbon::today());
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
    $this->requester->request($user, Carbon::today());
    Bus::assertNotDispatched(AnalyzePlanClampVoiceJob::class);

    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::today()->setHour(7),
    ]);
    $this->requester->request($user, Carbon::today());
    Bus::assertDispatchedTimes(AnalyzePlanClampVoiceJob::class, 1);
});
