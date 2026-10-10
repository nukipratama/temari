<?php

declare(strict_types=1);

use App\Enums\PlanRegenerationReason;
use App\Events\PlanRegenerated;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Listeners\RequestPlanNarrationOnPlanRegenerated;
use App\Models\AI\Analysis;
use App\Models\Season;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-17 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function regenerated(User $user, PlanRegenerationReason $reason): void
{
    app(RequestPlanNarrationOnPlanRegenerated::class)->handle(new PlanRegenerated($user, Carbon::today(), $reason));
}

it('runs inside the dispatch, never from the queue', function (): void {
    expect(app(RequestPlanNarrationOnPlanRegenerated::class))->not->toBeInstanceOf(ShouldQueue::class);
});

it('narrates the season for a manual regenerate', function (): void {
    $user = User::factory()->create();
    Season::factory()->for($user)->create();

    regenerated($user, PlanRegenerationReason::Manual);

    Bus::assertDispatchedTimes(AnalyzePlanSeasonVoiceJob::class, 1);
});

it('narrates onboarding only once the backfill has landed', function (): void {
    $user = User::factory()->create(['backfilled_at' => null]);
    Season::factory()->for($user)->create();

    regenerated($user, PlanRegenerationReason::Onboarding);
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);

    $user->forceFill(['backfilled_at' => now()])->saveQuietly();
    regenerated($user, PlanRegenerationReason::Onboarding);
    Bus::assertDispatchedTimes(AnalyzePlanSeasonVoiceJob::class, 1);
});

it('narrates a reconciled plan only for a recently active athlete', function (): void {
    $active = User::factory()->create();
    $inactive = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(8)]);
    Season::factory()->for($active)->create();
    Season::factory()->for($inactive)->create();

    regenerated($inactive, PlanRegenerationReason::Reconciliation);
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);

    regenerated($active, PlanRegenerationReason::Reconciliation);
    Bus::assertDispatchedTimes(AnalyzePlanSeasonVoiceJob::class, 1);
});

it('narrates a settings change once per cooldown window', function (): void {
    $user = User::factory()->create();
    Season::factory()->for($user)->create();

    regenerated($user, PlanRegenerationReason::Settings);
    regenerated($user, PlanRegenerationReason::Settings);

    Bus::assertDispatchedTimes(AnalyzePlanSeasonVoiceJob::class, 1);
});

it('fills the demo athlete\'s season rule-based on a settings change, with no job', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    $season = Season::factory()->for($user)->create();

    regenerated($user, PlanRegenerationReason::Settings);

    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
    expect(Analysis::query()->where('subject_type', Season::class)->where('subject_id', $season->id)->sole()->status)->toBe(AnalysisStatus::Done);
});
