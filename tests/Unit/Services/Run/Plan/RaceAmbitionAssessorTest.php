<?php

declare(strict_types=1);

use App\Enums\RaceAmbitionState;
use App\Models\Activity;
use App\Models\PerformanceEvidence;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\VdotEstimator;
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
        'user_id' => $user->id, 'activity_id' => Activity::factory()->for($user)->create()->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => $seconds,
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
        'user_id' => $user->id, 'activity_id' => Activity::factory()->for($user)->create()->id, 'kind' => 'test', 'distance_m' => $distanceM, 'elapsed_time_sec' => $seconds,
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

it('names the effort the supported time rests on', function (): void {
    tenKEvidence($this->user, 4200);

    $ambition = $this->assessor->assess($this->user, tenKRace($this->user, 4000));

    expect($ambition->basis)->toBe(['distance_m' => 10_000, 'performed_on' => '2026-09-24', 'activity_id' => PerformanceEvidence::query()->sole()->activity_id]);
});

it('sets the stepping stone 3 percent faster than supported for an unsupported goal only', function (int $goalSec, bool $steppingStone): void {
    tenKEvidence($this->user, 4200);

    $ambition = $this->assessor->assess($this->user, tenKRace($this->user, $goalSec));

    expect($ambition->steppingStoneTimeSec)->toBe($steppingStone ? RaceAmbitionAssessor::steppingStoneTimeSec($ambition->supportedTimeSec) : null)
        ->and($ambition->steppingStonePaceSecPerKm)->toBe($steppingStone ? (int) round($ambition->steppingStoneTimeSec / 10) : null)
        ->and($ambition->targetTimeSec)->toBe($goalSec);
})->with([
    'unsupported' => [3000, true],
    'ambitious' => [4000, false],
    'on track' => [4200, false],
]);

it('gives a low-evidence or unknown goal no stepping stone', function (): void {
    $unknown = $this->assessor->assess($this->user, tenKRace($this->user, 3000));
    PerformanceEvidence::query()->create([
        'user_id' => $this->user->id, 'activity_id' => Activity::factory()->for($this->user)->create()->id, 'kind' => 'test', 'distance_m' => 5_000, 'elapsed_time_sec' => 2000,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
    $lowEvidence = $this->assessor->assess($this->user, RaceGoal::factory()->for($this->user)->create([
        'distance_m' => 42_195, 'goal_time_sec' => 10_800, 'race_date' => Carbon::today()->addWeeks(12)->toDateString(),
    ]));

    expect($unknown->state)->toBe(RaceAmbitionState::Unknown)
        ->and($unknown->steppingStoneTimeSec)->toBeNull()
        ->and($lowEvidence->state)->toBe(RaceAmbitionState::LowEvidence)
        ->and($lowEvidence->steppingStoneTimeSec)->toBeNull();
});

it('moves the stepping stone with the supported time', function (): void {
    tenKEvidence($this->user, 4200);
    $race = tenKRace($this->user, 3000);
    $before = $this->assessor->assess($this->user, $race);
    PerformanceEvidence::query()->create([
        'user_id' => $this->user->id, 'activity_id' => Activity::factory()->for($this->user)->create()->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => 3900,
        'performed_on' => Carbon::today(), 'confirmed_at' => now(),
    ]);
    app()->forgetInstance(VdotEstimator::class);
    $after = app(RaceAmbitionAssessor::class)->assess($this->user, $race);

    expect($after->supportedTimeSec)->toBeLessThan($before->supportedTimeSec)
        ->and($after->steppingStoneTimeSec)->toBe(RaceAmbitionAssessor::steppingStoneTimeSec($after->supportedTimeSec))
        ->and($after->steppingStoneTimeSec)->toBeLessThan($before->steppingStoneTimeSec)
        ->and($after->targetTimeSec)->toBe(3000);
});

it('rounds the stepping stone to whole seconds at the edge of on track', function (int $supportedSec, int $steppingStoneSec): void {
    expect(RaceAmbitionAssessor::steppingStoneTimeSec($supportedSec))->toBe($steppingStoneSec);
})->with([
    'exact' => [4200, 4074],
    'rounds up' => [3150, 3056],
    'rounds down' => [3001, 2911],
]);
