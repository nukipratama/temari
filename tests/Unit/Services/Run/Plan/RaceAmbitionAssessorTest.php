<?php

declare(strict_types=1);

use App\Enums\RaceAmbitionState;
use App\Models\PerformanceEvidence;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Plan\RaceAmbitionAssessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-01 08:00:00');
    $this->assessor = app(RaceAmbitionAssessor::class);
    $this->user = User::factory()->create();
});
afterEach(fn () => Carbon::setTestNow());

function tenKEvidence(User $user, int $seconds): void
{
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => $seconds,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
}

function tenKRace(User $user, int $goalSec, int $weeks = 4): RaceGoal
{
    return RaceGoal::factory()->for($user)->create([
        'distance_m' => 10_000, 'goal_time_sec' => $goalSec, 'race_date' => Carbon::today()->addWeeks($weeks)->toDateString(),
    ]);
}

it('classifies a 50 minute 10K ambition against 70 minute capacity as unsupported and prescribes the supported effort', function (): void {
    tenKEvidence($this->user, 4200);

    $ambition = $this->assessor->assess($this->user, tenKRace($this->user, 3000));

    expect($ambition->state)->toBe(RaceAmbitionState::Unsupported)
        ->and($ambition->targetTimeSec)->toBe(3000)
        ->and($ambition->targetPaceSecPerKm)->toBe(300)
        ->and($ambition->supportedTimeSec)->toBeBetween(4190, 4215)
        ->and($ambition->supportedPaceSecPerKm)->toBeBetween(419, 422)
        ->and($ambition->prescribedTimeSec())->toBe($ambition->supportedTimeSec)
        ->and($ambition->gapPct)->toBeGreaterThan(6.0);
});

it('bands the target against supported pace at 3 and 6 percent', function (int $goalSec, RaceAmbitionState $state): void {
    tenKEvidence($this->user, 4200);

    expect($this->assessor->assess($this->user, tenKRace($this->user, $goalSec))->state)->toBe($state);
})->with([
    'slower than supported' => [4400, RaceAmbitionState::OnTrack],
    'exactly supported' => [4200, RaceAmbitionState::OnTrack],
    'within 3 percent' => [4100, RaceAmbitionState::OnTrack],
    'between 3 and 6 percent' => [4000, RaceAmbitionState::Ambitious],
    'just under 6 percent' => [3960, RaceAmbitionState::Ambitious],
    'over 6 percent' => [3900, RaceAmbitionState::Unsupported],
]);

it('keeps the athlete\'s own target when on track or ambitious', function (): void {
    tenKEvidence($this->user, 4200);

    expect($this->assessor->assess($this->user, tenKRace($this->user, 4000))->prescribedTimeSec())->toBe(4000);
});

it('does not classify without fitness evidence and keeps the target', function (): void {
    $ambition = $this->assessor->assess($this->user, tenKRace($this->user, 3000));

    expect($ambition->state)->toBe(RaceAmbitionState::Unknown)
        ->and($ambition->supportedTimeSec)->toBeNull()
        ->and($ambition->prescribedTimeSec())->toBe(3000);
});

it('does not classify a race beyond the marathon', function (): void {
    tenKEvidence($this->user, 4200);
    $race = RaceGoal::factory()->for($this->user)->create(['distance_m' => 80_000, 'goal_time_sec' => 36_000]);

    expect($this->assessor->assess($this->user, $race)->state)->toBe(RaceAmbitionState::Unknown);
});

function evidenceAt(User $user, int $distanceM, int $seconds): void
{
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'test', 'distance_m' => $distanceM, 'elapsed_time_sec' => $seconds,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
}

function marathonRace(User $user, int $goalSec): RaceGoal
{
    return RaceGoal::factory()->for($user)->create([
        'distance_m' => 42_195, 'goal_time_sec' => $goalSec, 'race_date' => Carbon::today()->addWeeks(16)->toDateString(),
    ]);
}

it('reads a marathon assessed from 10K evidence alone as low evidence and prescribes the supported time', function (): void {
    tenKEvidence($this->user, 2_700);
    $ambition = $this->assessor->assess($this->user, marathonRace($this->user, 12_000));

    expect($ambition->state)->toBe(RaceAmbitionState::LowEvidence)
        ->and($ambition->supportedTimeSec)->not->toBeNull()
        ->and($ambition->prescribedTimeSec())->toBe($ambition->supportedTimeSec);
});

it('never prescribes a low-evidence time faster than the athlete\'s own target', function (): void {
    tenKEvidence($this->user, 2_700);
    $ambition = $this->assessor->assess($this->user, marathonRace($this->user, 18_000));

    expect($ambition->state)->toBe(RaceAmbitionState::LowEvidence)
        ->and($ambition->prescribedTimeSec())->toBe(18_000);
});

it('applies the bands once the evidence covers at least half the race distance', function (int $evidenceM, RaceAmbitionState $state): void {
    evidenceAt($this->user, $evidenceM, (int) round($evidenceM * 0.27));

    expect($this->assessor->assess($this->user, tenKRace($this->user, 2_000))->state)->toBe($state);
})->with([
    'a 5K covers half a 10K' => [5_000, RaceAmbitionState::Unsupported],
    'a 3K does not' => [3_000, RaceAmbitionState::LowEvidence],
]);
