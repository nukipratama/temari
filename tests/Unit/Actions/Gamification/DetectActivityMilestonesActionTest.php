<?php

declare(strict_types=1);

use App\Actions\Gamification\DetectActivityMilestonesAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function milestoneDetector(): DetectActivityMilestonesAction
{
    return new DetectActivityMilestonesAction();
}

/** @return array{Activity, ActivityDetail} */
function buildActivity(User $user, string $startDate, int $distanceM, ?int $movingSec = null): array
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $startDate,
        'distance' => $distanceM,
        'moving_time' => $movingSec ?? max(1, (int) round($distanceM / 1000 * 360)),
        'elapsed_time' => $movingSec ?? max(1, (int) round($distanceM / 1000 * 360)),
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
        'elapsed_time' => 1800,
    ]);

    $milestones = milestoneDetector()($activity, $detail);

    expect($milestones)->toBe([]);
});

it('fires a first-ever distance milestone when the user crosses a threshold for the first time', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_300);

    $milestones = milestoneDetector()($activity, $detail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('first_ever_distance');
});

it('does not re-fire first-ever distance once a prior activity hit the same threshold', function (): void {
    $user = User::factory()->create();
    [$prior, $priorDetail] = buildActivity($user, '2026-05-15', 6_000);
    milestoneDetector()($prior, $priorDetail);

    [$later, $laterDetail] = buildActivity($user, '2026-05-21', 5_100);
    $milestones = milestoneDetector()($later, $laterDetail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->not->toContain('first_ever_distance');
});

it('fires longest_ever when a new activity beats the prior longest', function (): void {
    $user = User::factory()->create();
    [$short, $shortDetail] = buildActivity($user, '2026-05-15', 5_000);
    milestoneDetector()($short, $shortDetail);

    [$long, $longDetail] = buildActivity($user, '2026-05-21', 8_000);
    $milestones = milestoneDetector()($long, $longDetail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('longest_ever');
});

it('writes both milestone bodies with a period decimal', function (): void {
    $user = User::factory()->create();
    [$short, $shortDetail] = buildActivity($user, '2026-05-15', 5_000);
    milestoneDetector()($short, $shortDetail);

    [$long, $longDetail] = buildActivity($user, '2026-05-21', 10_470);
    $milestones = milestoneDetector()($long, $longDetail);

    $longest = nonNull(collect($milestones)->firstWhere('kind', 'longest_ever'));
    $firstEver = nonNull(collect($milestones)->firstWhere('kind', 'first_ever_distance'));

    expect($longest['body'])->toContain('10.5 km')
        ->and($longest['body'])->not->toContain('10,5')
        ->and($firstEver['body'])->toContain('10.5 km')
        ->and($firstEver['body'])->not->toContain('10,5');
});

it('includes a PR milestone when categories are passed in', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000);

    $milestones = milestoneDetector()($activity, $detail, ['5km']);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('pr');
});

it('sorts milestones with PR first (highest priority)', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 10_500, 2_700); // ~4:17 pace → sub-5

    $milestones = milestoneDetector()($activity, $detail, ['10km']);

    expect($milestones[0]['kind'])->toBe('pr');
});

it('is idempotent — re-running the detector on the same activity returns the cached payload without re-querying', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_500);

    $first = milestoneDetector()($activity, $detail);
    $activity->refresh();
    $original = $activity->milestones_detected_at;

    // Sleep imitates a re-sync minutes later; the detected_at timestamp should stay frozen.
    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    $second = milestoneDetector()($activity, $detail);
    $activity->refresh();

    // JSON round-trip re-orders associative array keys, so compare canonicalised.
    expect(array_column($second, 'kind'))->toBe(array_column($first, 'kind'))
        ->and($activity->milestones_detected_at?->toIso8601String())->toBe($original?->toIso8601String());

    Carbon::setTestNow();
});

it('skips the first-ever-distance milestone when the run is below the smallest threshold (1km)', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 500); // 0.5 km

    $milestones = milestoneDetector()($activity, $detail);

    expect(array_column($milestones, 'kind'))->not->toContain('first_ever_distance');
});

it('skips the first-ever-pace milestone for slow pace above all thresholds', function (): void {
    $user = User::factory()->create();
    // 5 km in 50 minutes = 10:00/km, slower than slowest threshold (7:00).
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 3_000);

    $milestones = milestoneDetector()($activity, $detail);

    expect(array_column($milestones, 'kind'))->not->toContain('first_ever_pace');
});

it('awards the fastest pace tier crossed, not the slowest (regression for break-on-first-threshold)', function (): void {
    $user = User::factory()->create();
    // 5 km in 1425 s = 4:45/km — beats sub-5:00 (300s) but not sub-4:30 (270s).
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 1_425);

    $milestones = milestoneDetector()($activity, $detail);
    $pace = nonNull(collect($milestones)->firstWhere('kind', 'first_ever_pace'));

    expect($pace['label'])->toContain('5:00')
        ->and($pace['label'])->not->toContain('7:00');
});

it('does not award sub-7:00 to a run just over the threshold (7:00.4/km)', function (): void {
    $user = User::factory()->create();
    // 5 km in 2102 s = 420.4 s/km = 7:00.4/km, genuinely slower than 7:00.
    // Rounding down to 420 must not clear the <= 420 threshold.
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 2_102);

    $milestones = milestoneDetector()($activity, $detail);

    expect(array_column($milestones, 'kind'))->not->toContain('first_ever_pace');
});

it('awards sub-7:00 to a run exactly at the threshold (7:00.0/km)', function (): void {
    $user = User::factory()->create();
    // 5 km in 2100 s = 420.0 s/km = 7:00/km exactly, which qualifies (<= 420).
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_000, 2_100);

    $milestones = milestoneDetector()($activity, $detail);
    $pace = nonNull(collect($milestones)->firstWhere('kind', 'first_ever_pace'));

    expect($pace['label'])->toContain('7:00');
});

it('labels half marathon and marathon distance milestones with their named forms', function (): void {
    $user = User::factory()->create();

    [$half, $halfDetail] = buildActivity($user, '2026-05-21', 21_200); // just over 21.1 km
    $halfMilestones = milestoneDetector()($half, $halfDetail);
    $halfDistance = nonNull(collect($halfMilestones)->firstWhere('kind', 'first_ever_distance'));
    expect($halfDistance['label'])->toContain('Half Marathon');

    [$marathon, $marathonDetail] = buildActivity($user, '2026-05-22', 42_300); // just over 42.2 km
    $marathonMilestones = milestoneDetector()($marathon, $marathonDetail);
    $marathonDistance = nonNull(collect($marathonMilestones)->firstWhere('kind', 'first_ever_distance'));
    expect($marathonDistance['label'])->toContain('Marathon');
});

it('returns the cached payload as a plain array when re-detected', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_500);
    milestoneDetector()($activity, $detail);

    // Simulate dismissal: payload nulled but detected_at stays.
    $activity->update(['milestone_payload' => null]);

    $milestones = milestoneDetector()($activity, $detail);

    expect($milestones)->toBe([]);
});

it('formats PR category labels for half_marathon and marathon distance PRs', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 21_200);

    $milestones = milestoneDetector()($activity, $detail, ['half_marathon', 'marathon', '15km']);

    $prMilestones = collect($milestones)->where('kind', 'pr');
    $bodies = $prMilestones->pluck('body')->all();
    expect($bodies)->toContain('New PR at Half Marathon. Your old number held until today.')
        ->and($bodies)->toContain('New PR at Marathon. Your old number held until today.')
        ->and($bodies)->toContain('New PR at 15 km. Your old number held until today.');
});

it('treats older activities synced later as not setting a new "first ever" for younger rows', function (): void {
    $user = User::factory()->create();
    // The "new" activity dated 2026-05-21, detected first.
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_100);
    milestoneDetector()($activity, $detail);

    // Now an older backfilled run dated 2026-01-01 arrives — it really WAS the first ever crossing.
    [$older, $olderDetail] = buildActivity($user, '2026-01-01', 5_300);
    $milestones = milestoneDetector()($older, $olderDetail);

    $kinds = array_column($milestones, 'kind');
    expect($kinds)->toContain('first_ever_distance');
});

it('drops the trailing decimal from a whole-kilometre threshold label', function (): void {
    $user = User::factory()->create();
    [$activity, $detail] = buildActivity($user, '2026-05-21', 5_300);

    $distance = nonNull(collect(milestoneDetector()($activity, $detail))->firstWhere('kind', 'first_ever_distance'));

    expect($distance['label'])->toBe('First 5 km');
});
