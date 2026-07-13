<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Gamification\MilestoneDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->detector = app(MilestoneDetector::class);
});

function buildActivity(User $user, string $startDate, int $distanceM, ?int $movingSec = null): array
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $startDate,
        'distance' => $distanceM,
        'moving_time' => $movingSec ?? max(1, (int) round($distanceM / 1000 * 360)),
    ]);

    return [$activity, $detail];
}

it('returns empty list when start_date_local is missing', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => null,
        'distance' => 5000,
        'moving_time' => 1800,
    ]);

    $milestones = $this->detector->detect($activity, $detail);

    expect($milestones)->toBe([]);
});

it('fires a first-ever distance milestone when the user crosses a threshold for the first time', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_300);

    $milestones = $this->detector->detect($activity, $detail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('first_ever_distance');
});

it('does not re-fire first-ever distance once a prior activity hit the same threshold', function (): void {
    $user = User::factory()->create();
    [$prior, $priorDetail] = buildActivity($user, '2026-05-15', 6_000);
    $this->detector->detect($prior, $priorDetail);

    [$later, $laterDetail] = buildActivity($user, '2026-05-21', 5_100);
    $milestones = $this->detector->detect($later, $laterDetail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->not->toContain('first_ever_distance');
});

it('fires longest_ever when a new activity beats the prior longest', function (): void {
    $user = User::factory()->create();
    [$short, $shortDetail] = buildActivity($user, '2026-05-15', 5_000);
    $this->detector->detect($short, $shortDetail);

    [$long, $longDetail] = buildActivity($user, '2026-05-21', 8_000);
    $milestones = $this->detector->detect($long, $longDetail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('longest_ever');
});

it('includes a PR milestone when categories are passed in', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000);

    $milestones = $this->detector->detect($activity, $detail, ['5km']);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('pr');
});

it('sorts milestones with PR first (highest priority)', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 10_500, 2_700); // ~4:17 pace → sub-5

    $milestones = $this->detector->detect($activity, $detail, ['10km']);

    expect($milestones[0]['kind'])->toBe('pr');
});

it('is idempotent — re-running the detector on the same activity returns the cached payload without re-querying', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_500);

    $first = $this->detector->detect($activity, $detail);
    $activity->refresh();
    $original = $activity->milestones_detected_at;

    // Sleep imitates a re-sync minutes later; the detected_at timestamp should stay frozen.
    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    $second = $this->detector->detect($activity, $detail);
    $activity->refresh();

    // JSON round-trip re-orders associative array keys, so compare canonicalised.
    expect(array_column($second, 'kind'))->toBe(array_column($first, 'kind'))
        ->and($activity->milestones_detected_at?->toIso8601String())->toBe($original?->toIso8601String());

    Carbon::setTestNow();
});

it('skips the first-ever-distance milestone when the run is below the smallest threshold (1km)', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 500); // 0.5 km

    $milestones = $this->detector->detect($activity, $detail);

    expect(array_column($milestones, 'kind'))->not->toContain('first_ever_distance');
});

it('skips the first-ever-pace milestone for slow pace above all thresholds', function (): void {
    $user = User::factory()->create();
    // 5 km in 50 minutes = 10:00/km, slower than slowest threshold (7:00).
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 3_000);

    $milestones = $this->detector->detect($activity, $detail);

    expect(array_column($milestones, 'kind'))->not->toContain('first_ever_pace');
});

it('awards the fastest pace tier crossed, not the slowest (regression for break-on-first-threshold)', function (): void {
    $user = User::factory()->create();
    // 5 km in 1425 s = 4:45/km — beats sub-5:00 (300s) but not sub-4:30 (270s).
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 1_425);

    $milestones = $this->detector->detect($activity, $detail);
    $pace = collect($milestones)->firstWhere('kind', 'first_ever_pace');

    expect($pace)->not->toBeNull()
        ->and($pace['label'])->toContain('5:00')
        ->and($pace['label'])->not->toContain('7:00');
});

it('does not award sub-7:00 to a run just over the threshold (7:00.4/km)', function (): void {
    $user = User::factory()->create();
    // 5 km in 2102 s = 420.4 s/km = 7:00.4/km, genuinely slower than 7:00.
    // Rounding down to 420 used to (wrongly) clear the <= 420 threshold.
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 2_102);

    $milestones = $this->detector->detect($activity, $detail);

    expect(array_column($milestones, 'kind'))->not->toContain('first_ever_pace');
});

it('awards sub-7:00 to a run exactly at the threshold (7:00.0/km)', function (): void {
    $user = User::factory()->create();
    // 5 km in 2100 s = 420.0 s/km = 7:00/km exactly, which qualifies (<= 420).
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 2_100);

    $milestones = $this->detector->detect($activity, $detail);
    $pace = collect($milestones)->firstWhere('kind', 'first_ever_pace');

    expect($pace)->not->toBeNull()
        ->and($pace['label'])->toContain('7:00');
});

it('labels half marathon and marathon distance milestones with their named forms', function (): void {
    $user = User::factory()->create();

    [$half, $halfDetail] = buildActivity($user, '2026-05-21', 21_200); // just over 21.1 km
    $halfMilestones = $this->detector->detect($half, $halfDetail);
    $halfDistance = collect($halfMilestones)->firstWhere('kind', 'first_ever_distance');
    expect($halfDistance['label'])->toContain('Half Marathon');

    [$marathon, $marathonDetail] = buildActivity($user, '2026-05-22', 42_300); // just over 42.2 km
    $marathonMilestones = $this->detector->detect($marathon, $marathonDetail);
    $marathonDistance = collect($marathonMilestones)->firstWhere('kind', 'first_ever_distance');
    expect($marathonDistance['label'])->toContain('Marathon');
});

it('returns the cached payload as a plain array when re-detected', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_500);
    $this->detector->detect($activity, $detail);

    // Simulate dismissal: payload nulled but detected_at stays.
    $activity->update(['milestone_payload' => null]);

    $milestones = $this->detector->detect($activity, $detail);

    expect($milestones)->toBe([]);
});

it('formats PR category labels for half_marathon and marathon distance PRs', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 21_200);

    $milestones = $this->detector->detect($activity, $detail, ['half_marathon', 'marathon', '15km']);

    $prMilestones = collect($milestones)->where('kind', 'pr');
    $bodies = $prMilestones->pluck('body')->all();
    expect($bodies)->toContain('Kamu baru saja memecahkan PR di Half Marathon. Aku catat.')
        ->and($bodies)->toContain('Kamu baru saja memecahkan PR di Marathon. Aku catat.')
        ->and($bodies)->toContain('Kamu baru saja memecahkan PR di 15 km. Aku catat.');
});

it('treats older activities synced later as not setting a new "first ever" for younger rows', function (): void {
    $user = User::factory()->create();
    // The "new" activity dated 2026-05-21, detected first.
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_100);
    $this->detector->detect($activity, $detail);

    // Now an older backfilled run dated 2026-01-01 arrives — it really WAS the first ever crossing.
    [$older, $olderDetail] = buildActivity($user, '2026-01-01', 5_300);
    $milestones = $this->detector->detect($older, $olderDetail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('first_ever_distance');
});
