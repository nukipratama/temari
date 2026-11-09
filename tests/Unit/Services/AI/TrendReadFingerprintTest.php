<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\AI\TrendReadFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function trendFingerprintRun(User $user, string $on, float $metres = 8_000.0): void
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse($on.' 07:00:00'), 'distance' => $metres]);
}

function trendFingerprint(User $user): string
{
    return app(TrendReadFingerprint::class)->forUser($user);
}

function doneTrendRead(User $user, ?string $fingerprint): Analysis
{
    return Analysis::factory()->done("a read.\n\nThe body.")->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => AnalysisType::TREND_READ_RANGES[0],
        'content_fingerprint' => $fingerprint,
    ]);
}

it('returns unchanged when no completed trend read exists', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
    $user = User::factory()->create();

    expect(app(TrendReadFingerprint::class)->changed($user))->toBeFalse();
});

it('holds through a run in the week still open, and moves once that week closes', function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    trendFingerprintRun($user, '2026-08-05');
    $before = trendFingerprint($user);

    trendFingerprintRun($user, '2026-08-18');
    Carbon::setTestNow('2026-08-23 21:00:00');

    expect(trendFingerprint($user))->toBe($before);

    Carbon::setTestNow('2026-08-24 06:00:00');

    expect(trendFingerprint($user))->not->toBe($before);
});

it('moves when a run lands late inside a closed week', function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    $before = trendFingerprint($user);

    trendFingerprintRun($user, '2026-08-12');

    expect(trendFingerprint($user))->not->toBe($before);
});

it('moves when a race is set, revised, moved or retired, and not on a rename', function (Closure $change, bool $moves): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-11', 'distance_m' => 21_097, 'goal_time_sec' => 7_200, 'name' => 'City half']);
    $before = trendFingerprint($user);

    $change($race, $user);

    expect(trendFingerprint($user) !== $before)->toBe($moves);
})->with([
    'goal time' => [fn (RaceGoal $race) => $race->update(['goal_time_sec' => 7_000]), true],
    'race date' => [fn (RaceGoal $race) => $race->update(['race_date' => '2026-10-18']), true],
    'retired' => [fn (RaceGoal $race) => $race->update(['completed_at' => now()]), true],
    'deleted' => [fn (RaceGoal $race) => $race->delete(), true],
    'replaced' => [function (RaceGoal $race, User $user): void {
        $race->update(['completed_at' => now()]);
        RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-11', 'distance_m' => 21_097, 'goal_time_sec' => 7_200]);
    }, true],
    'renamed' => [fn (RaceGoal $race) => $race->update(['name' => 'Harbour half']), false],
]);

it('moves when a race is set where there was none', function (): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    $before = trendFingerprint($user);

    RaceGoal::factory()->for($user)->create();

    expect(trendFingerprint($user))->not->toBe($before);
});

it('reports a done read changed only when its stamped fingerprint differs from now', function (?string $stamped, bool $changed): void {
    Carbon::setTestNow('2026-08-19 12:00:00');
    $user = User::factory()->create();
    doneTrendRead($user, $stamped === 'current' ? trendFingerprint($user) : $stamped);

    expect(app(TrendReadFingerprint::class)->changed($user))->toBe($changed);
})->with([
    'same' => ['current', false],
    'different' => ['stale', true],
    'unstamped' => [null, true],
]);
