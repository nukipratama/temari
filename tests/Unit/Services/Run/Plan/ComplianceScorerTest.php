<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\SegmentKey;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\ComplianceScorer;
use App\Services\Run\Plan\RecommendationHistory;
use App\Services\Run\Plan\SessionSegment;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('grades the shown target rather than a later reconstructed distance', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-10-01');
    $history = app(RecommendationHistory::class);
    $revision = $history->record($user->id, '2026-10-01', ['session_type' => 'easy'], [
        'session_type' => 'easy', 'distance_km' => 6.0, 'segments' => [], 'paces' => null, 'skipped' => false,
    ]);
    Carbon::setTestNow('2026-10-01 01:00:00 UTC');
    $history->shown($revision, (string) Str::uuid());
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => '2026-10-01 09:00:00', 'start_date_utc' => '2026-10-01 02:00:00', 'distance' => 6000,
    ]);
    $verdict = app(ComplianceScorer::class)->verdictsFor($user, new Collection([$row]), Carbon::parse('2026-10-02'))['2026-10-01'];
    expect($verdict['prescribed_km'])->toBe(6.0)->and($verdict['distance_score'])->toBe(100)
        ->and($verdict['intent']['evidence']['recommendation_revision_id'])->toBe($revision->id);
    Carbon::setTestNow();
});

function scorerDay(User $user, string $date, array $attributes = []): PlannedSession
{
    return PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Easy,
        ...$attributes,
    ]);
}

function scorerRun(User $user, string $date, float $km): void
{
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'distance' => $km * 1000,
    ]);
}

it('records a day still in progress once the run has already earned it', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached)
        ->and($row->compliance_score)->toBeGreaterThan(0);
});

it('leaves a day still in progress planned when the run falls short', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 0.2);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Planned)
        ->and($row->compliance_score)->toBeNull();
});

it('lifts a past day the daily pass already wrote off, which nothing else would revisit', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'status' => PlannedSessionStatus::Missed,
        'compliance_score' => 0,
    ]);
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached);
});

it('never writes a day down: a smaller verdict leaves the earned one standing', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'status' => PlannedSessionStatus::Overreached,
        'compliance_score' => 400,
    ]);
    // Comfortably credited, but far below the 400 already on the row.
    scorerRun($user, '2026-08-05', 4.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached)
        ->and($row->compliance_score)->toBe(400);
});

it('leaves an excused day alone, however much was logged against it', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'status' => PlannedSessionStatus::Skip,
        'skipped' => true,
    ]);
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Skip);
});

it('does nothing on a date the plan never asked for', function (): void {
    $user = User::factory()->create();
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-07'));

    expect(PlannedSession::query()->count())->toBe(0);
});

it('returns no verdicts for an empty set of rows', function (): void {
    $user = User::factory()->create();

    expect(app(ComplianceScorer::class)->verdictsFor($user, PlannedSession::query()->whereRaw('1 = 0')->get(), Carbon::parse('2026-08-07')))
        ->toBe([]);
});

it('credits a run whose local date is a day ahead of the server clock', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-06');
    scorerRun($user, '2026-08-06', 40.0);

    // The activity's own local date (2026-08-06) is ahead of $today
    // (2026-08-05) — e.g. a WIT athlete just after their local midnight,
    // while the server's Asia/Jakarta clock still reads the previous day.
    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-06'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached);
});

it('records what the day was judged against, so the denominator outlives the baseline', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 40.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->prescribed_km)->toBeGreaterThan(0.0);
});

it('measures a day against the baseline as it stood that day, not against today\'s', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 40.0);

    // Two weeks of history the athlete only accumulates AFTER the day being
    // judged. Scoring as-of today would measure the day against them.
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-08-09', 'distance_km' => 120.0, 'runs' => 6]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-08-16', 'distance_km' => 140.0, 'runs' => 6]);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-20'));

    $asOf = app(TrainingBaseline::class)->forUser($user, Carbon::parse('2026-08-05'))['long_run_km'];
    $expected = PlanRenderer::plannedKmByDate(PlannedSession::query()->where('user_id', $user->id)->get(), $asOf, INF, selfScaled: true)['2026-08-05'];

    expect($row->refresh()->prescribed_km)->toBe($expected);
});

/**
 * The bug this closes: an athlete told at 00:01 to run an eased 3.6 instead of
 * the 5.9 tempo on the board, who obeys, was graded 3.6/5.9 = 61% and landed on
 * `partial` for following the app's own advice.
 */
it('grades a clamped day against the eased distance it actually asked for', function (): void {
    $user = User::factory()->create();
    // 1.2 against a stored tempo ask of ~3.4 is 35% — `partial` — without the
    // substitution, which is exactly the shape of the reported bug.
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Tempo,
        'clamped_km' => 1.2,
    ]);
    scorerRun($user, '2026-08-05', 1.2);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Done)
        ->and($row->compliance_score)->toBe(100)
        // The ask recorded beside the verdict is the one they were set, so the
        // card cannot show "3.4 asked · 1.2 run · DONE".
        ->and($row->prescribed_km)->toBe(1.2);
});

/** Told to do less and doing the original session anyway is an overreach. */
it('reads the full original session on a clamped day as going past the ask', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Tempo,
        'clamped_km' => 1.2,
    ]);
    scorerRun($user, '2026-08-05', 3.4);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Overreached);
});

/** No clamp recorded, nothing changes: the stored session is still the ask. */
it('grades an unclamped day against the stored session exactly as before', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    scorerRun($user, '2026-08-05', 3.6);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    // The stored figure is whatever the renderer derives for a tempo at this
    // baseline; the point is only that 3.6 was NOT substituted in for it.
    expect($row->refresh()->clamped_km)->toBeNull()
        ->and($row->prescribed_km)->not->toBe(3.6)
        ->and($row->prescribed_km)->toBeGreaterThan(0.0);
});

/**
 * The eased distance is the one the athlete was actually told to run, so a
 * long day is measured against it rather than the un-eased session it replaced.
 */
it('measures an eased long day against the eased distance, not the stored one', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Long,
        'clamped_km' => 4.0,
    ]);
    scorerRun($user, '2026-08-05', 4.0);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Done)
        ->and($row->prescribed_km)->toBe(4.0);
});

/**
 * A readiness clamp that downgraded the day to a full rest makes it excused,
 * and no crediting rule may reach past that into a verdict.
 */
it('leaves a rest-clamped day excused however the day was actually run', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Long,
        'rest_clamped_at' => Carbon::parse('2026-08-05 00:01:00'),
    ]);
    scorerRun($user, '2026-08-05', 2.0);
    scorerRun($user, '2026-08-05', 2.0);

    $verdicts = app(ComplianceScorer::class)->verdictsFor($user, PlannedSession::query()->whereKey($row->id)->get(), Carbon::parse('2026-08-20'));

    expect($verdicts['2026-08-05']['status'])->toBe(PlannedSessionStatus::Skip)
        ->and($verdicts['2026-08-05']['score'])->toBeNull();
});

/** @return array{easy: int, marathon: int, threshold: int, interval: int} */
function scorerPaces(User $user, string $date): array
{
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1500, 'set_at' => '2026-07-01']);
    seedConfirmedEffort($user, 5000, 1500, Carbon::parse('2026-07-01'));

    /** @var array{easy: int, marathon: int, threshold: int, interval: int} */
    return app(TrainingPaceCalculator::class)->fromVdotResult(app(VdotEstimator::class)->estimate($user, Carbon::parse($date)));
}

/** @param  array<string, mixed>  $summary */
function scorerPacedRun(User $user, string $date, float $km, int $secPerKm, array $summary = []): void
{
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse($date.' 06:00:00'),
        'distance' => $km * 1000,
        'moving_time' => (int) round($km * $secPerKm),
        'elapsed_time' => (int) round($km * $secPerKm),
        'stream_summary' => $summary,
    ]);
}

/** @return array<string, string> */
function everyWindowAt(int $secPerKm): array
{
    $pace = sprintf('%d:%02d', intdiv($secPerKm, 60), $secPerKm % 60);

    return collect(['30s', '1min', '3min', '5min', '10min', '20min', '30min', '60min'])
        ->mapWithKeys(static fn (string $window): array => ["best_{$window}_pace" => $pace])
        ->all();
}

/** @return array<string, mixed> */
function scorerVerdict(User $user, PlannedSession $row): array
{
    return app(ComplianceScorer::class)->verdictsFor($user, PlannedSession::query()->whereKey($row->id)->get(), Carbon::parse('2026-08-20'))[$row->date->toDateString()];
}

it('grades a tempo eased to easy and run easy as intent hit, on distance alone', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo, 'clamped_km' => 6.4]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), shownEasyEffective());
    shownRun($user, '2026-08-05', 5.3, 2136, ['best_1min_pace' => '4:30']);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Partial)
        ->and($verdict['score'])->toBe(83)
        ->and($verdict['distance_score'])->toBe(83);
});

it('grades a long day eased to an easy run as done when split across two runs', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Long, 'clamped_km' => 6.8]);
    showAdvice($user, '2026-08-05', ['session_type' => 'long', 'distance_km' => 14.0, 'segments' => shownEasySegments()], [
        ...shownEasyEffective(), 'distance_km' => 6.8,
    ]);
    shownRun($user, '2026-08-05', 4.5, 1800);
    shownRun($user, '2026-08-05', 3.6, 1440, [], '17:00:00');

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Done)
        ->and($verdict['distance_score'])->toBe(119)
        ->and($verdict['prescribed_km'])->toBe(6.8);
});

it('keeps a long day split in two partial when the clamp left its size alone', function (array $attributes): void {
    $user = User::factory()->create();
    $paces = scorerPaces($user, '2026-08-05');
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Long, ...$attributes]);
    $askedKm = (float) scorerVerdict($user, $row)['prescribed_km'];
    scorerPacedRun($user, '2026-08-05', round($askedKm * 0.6, 1), $paces['easy']);
    scorerPacedRun($user, '2026-08-05', round($askedKm * 0.6, 1), $paces['easy']);

    expect(scorerVerdict($user, $row)['status'])->toBe(PlannedSessionStatus::Partial);
})->with([
    'pace eased at ModerateOk' => [['eased_pace_sec_per_km' => 420]],
    'not clamped' => [[]],
]);

it('reads an uneased tempo run all easy as partial even at full distance', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 8.0, 3200, everyWindowAt(400));

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-20'));

    expect($row->refresh()->status)->toBe(PlannedSessionStatus::Partial)
        ->and($row->compliance_score)->toBe(84)
        ->and($row->distance_score)->toBe(100);
});

it('reads a tempo that reached its block within tolerance as done', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 8.0, 3200, everyWindowAt(315));

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Done);
});

it('reads an easy run faster than marathon pace as overreached', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    showAdvice($user, '2026-08-05', ['session_type' => 'easy', 'segments' => shownEasySegments()], [
        'session_type' => 'easy', 'distance_km' => 6.4, 'segments' => shownEasySegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 6.4, 2048);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Overreached)
        ->and($verdict['score'])->toBe(100);
});

it('grades intervals on auto-km laps with no other signal on distance alone', function (): void {
    $user = User::factory()->create();
    scorerPaces($user, '2026-08-05');
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Interval]);
    $askedKm = (float) scorerVerdict($user, $row)['prescribed_km'];
    $laps = array_map(static fn (int $i): array => ['lap' => $i, 'distance_m' => 1000, 'elapsed_sec' => 360, 'pace' => '6:00'], range(1, max(1, (int) floor($askedKm))));
    scorerPacedRun($user, '2026-08-05', $askedKm, 360, ['laps' => $laps]);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Done);
});

it('does not judge intent on a day that was never credited', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');

    $verdict = scorerVerdict($user, $row);

    expect($verdict['status'])->toBe(PlannedSessionStatus::Missed)
        ->and($verdict['intent'])->toBeNull()
        ->and($verdict['distance_score'])->toBe(0);
});

/**
 * The verdict a narrator reads later has to be exactly the one the grade
 * used, so it is persisted rather than recomputed — see
 * `docs/decisions/a-day-is-graded-on-distance-and-intent.md`.
 */
it('persists the intent verdict and its evidence beside the grade', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    showAdvice($user, '2026-08-05', ['session_type' => 'easy', 'segments' => shownEasySegments()], [
        'session_type' => 'easy', 'distance_km' => 6.4, 'segments' => shownEasySegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 6.4, 2048);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->intent_verdict)->toBe(IntentVerdict::TooHard)
        ->and($row->intent_evidence)->toBeArray()->not->toBeEmpty();
});

/** A day the day the plan never judges (no intent to fold in) persists none. */
it('persists no intent verdict for a day never credited', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    scorerRun($user, '2026-08-05', 0.2);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-05'));

    expect($row->refresh()->intent_verdict)->toBeNull()
        ->and($row->intent_evidence)->toBeNull();
});

const SHOWN_PACES = ['easy' => 400, 'marathon' => 340, 'threshold' => 310, 'interval' => 285];

/** @return list<array<string, mixed>> */
function shownTempoSegments(float $blockMinutes = 20.0): array
{
    return [
        new SessionSegment(SegmentKey::Warmup, 10.0, 'Z2', PaceBand::Easy, 400, 1.5)->toArray(),
        new SessionSegment(SegmentKey::Main, $blockMinutes, 'Z4', PaceBand::Threshold, 310, 4.0)->toArray(),
    ];
}

/** @return list<array<string, mixed>> */
function shownEasySegments(): array
{
    return [new SessionSegment(SegmentKey::Main, 40.0, 'Z2', PaceBand::Easy, 400, 6.4)->toArray()];
}

/**
 * @param  array<string, mixed>  $original
 * @param  array<string, mixed>  $effective
 */
function showAdvice(User $user, string $date, array $original, array $effective): RecommendationRevision
{
    $revision = app(RecommendationHistory::class)->record($user->id, $date, $original, $effective);
    RecommendationView::query()->create([
        'recommendation_revision_id' => $revision->id,
        'observation_id' => (string) Str::uuid(),
        'shown_at' => Carbon::parse($date.' 00:30:00', 'UTC'),
    ]);

    return $revision;
}

/** @return array<string, mixed> */
function shownTempoOriginal(): array
{
    return ['session_type' => 'tempo', 'phase' => 'build', 'hard_minutes' => 20, 'distance_km' => 8.0, 'reason' => null, 'segments' => shownTempoSegments()];
}

/**
 * @param  list<string>  $reasons
 * @return array<string, mixed>
 */
function shownEasyEffective(string $ceiling = 'moderate_ok', array $reasons = []): array
{
    return [
        'session_type' => 'easy', 'distance_km' => 6.4, 'segments' => shownEasySegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => 'ease off',
        'readiness_assessment' => ['ceiling' => $ceiling, 'reasons' => $reasons, 'inputs' => []],
    ];
}

/** @param  array<string, mixed>  $summary */
function shownRun(User $user, string $date, float $km, int $movingSec, array $summary = [], string $time = '06:00:00'): ActivityDetail
{
    return ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse("{$date} {$time}"),
        'start_date_utc' => Carbon::parse("{$date} {$time}", 'UTC')->addHour(),
        'distance' => $km * 1000,
        'moving_time' => $movingSec,
        'elapsed_time' => $movingSec,
        'stream_summary' => $summary,
    ]);
}

/** @return array<string, mixed> */
function controlledTempoSummary(): array
{
    return ['best_20min_pace' => '5:15', 'time_in_zone_min' => ['Z1' => 2, 'Z2' => 14, 'Z3' => 4, 'Z4' => 21, 'Z5' => 0]];
}

it('reads a controlled tempo that exceeded advice eased for a mild concern as the original tempo completed', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo, 'clamped_km' => 6.4]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), shownEasyEffective('moderate_ok', ['moderate_fatigue_or_soreness_reported']));
    shownRun($user, '2026-08-05', 8.0, 2800, controlledTempoSummary());

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Overreached)
        ->and($verdict['intent']['evidence'])->toMatchArray([
            'advice_history' => 'shown', 'eased_from' => 'tempo', 'concern' => 'mild', 'original_completed' => 'controlled',
            'stimulus_family' => 'tempo', 'stimulus_minutes' => 20.0, 'stimulus_source' => 'window',
        ])->not->toHaveKey('quality_progression');
});

it('keeps a strong recovery concern distinct when the eased session was run hard anyway', function (string $ceiling, string $reason): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo, 'clamped_km' => 6.4]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), shownEasyEffective($ceiling, [$reason]));
    shownRun($user, '2026-08-05', 8.0, 2800, controlledTempoSummary());

    expect(scorerVerdict($user, $row)['intent']['evidence'])->toMatchArray(['eased_from' => 'tempo', 'concern' => 'strong', 'original_completed' => 'controlled']);
})->with([
    'severe fatigue' => ['easy_only', 'severe_fatigue_or_soreness_reported'],
    'pain' => ['rest', 'concerning_pain_reported'],
    'illness' => ['easy_only', 'illness_reported'],
]);

it('treats a severe reason under a ceiling that still allowed moderate work as a mild concern', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo, 'clamped_km' => 6.4]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), shownEasyEffective('moderate_ok', ['severe_fatigue_or_soreness_reported']));
    shownRun($user, '2026-08-05', 8.0, 2800, controlledTempoSummary());

    expect(scorerVerdict($user, $row)['intent']['evidence']['concern'])->toBe('mild');
});

it('does not count an eased tempo run easy toward quality progression', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo, 'clamped_km' => 6.4]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), shownEasyEffective());
    shownRun($user, '2026-08-05', 6.4, 2580);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['intent']['evidence'])->toMatchArray(['eased_from' => 'tempo', 'concern' => 'mild', 'stimulus_family' => 'easy'])
        ->not->toHaveKeys(['quality_progression', 'original_completed']);
});

it('reads self-added hard work on an easy day as an unplanned hard effort that teaches no quality', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05');
    showAdvice($user, '2026-08-05', ['session_type' => 'easy', 'phase' => 'build', 'hard_minutes' => null, 'distance_km' => 6.4, 'reason' => null, 'segments' => shownEasySegments()], [
        'session_type' => 'easy', 'distance_km' => 6.4, 'segments' => shownEasySegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 6.4, 2100, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 1380]);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($verdict['intent']['evidence'])->toMatchArray(['concern' => 'none', 'stimulus_family' => 'hard', 'stimulus_minutes' => 23.0])
        ->not->toHaveKeys(['eased_from', 'quality_progression']);
});

it('marks a heart-rate verdict as resting on estimated zones until the athlete measures or syncs them', function (?string $source, bool $estimated): void {
    $user = User::factory()->create();
    if ($source !== null) {
        RunnerProfile::factory()->for($user)->create(['source' => $source]);
    }
    $row = scorerDay($user, '2026-08-05');
    showAdvice($user, '2026-08-05', ['session_type' => 'easy', 'phase' => 'build', 'hard_minutes' => null, 'distance_km' => 6.4, 'reason' => null, 'segments' => shownEasySegments()], [
        'session_type' => 'easy', 'distance_km' => 6.4, 'segments' => shownEasySegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 6.4, 2100, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 1380]);

    $evidence = scorerVerdict($user, $row)['intent']['evidence'];

    expect($evidence['stimulus_source'])->toBe('heart_rate')
        ->and(($evidence['zones'] ?? null) === 'estimated')->toBe($estimated);
})->with([
    'no profile, default max' => [null, true],
    'raised to an observed peak' => ['observed', true],
    'synced from Strava' => ['strava', false],
    'set by hand' => ['manual', false],
]);

it('grades an easy day on the heart-rate cap alone once the athlete has zones of their own', function (?string $source, IntentVerdict $expected): void {
    $user = User::factory()->create();
    if ($source !== null) {
        RunnerProfile::factory()->for($user)->create(['source' => $source]);
    }
    $row = scorerDay($user, '2026-08-05');
    showAdvice($user, '2026-08-05', ['session_type' => 'easy', 'phase' => 'build', 'hard_minutes' => null, 'distance_km' => 6.4, 'reason' => null, 'segments' => shownEasySegments()], [
        'session_type' => 'easy', 'distance_km' => 6.4, 'segments' => shownEasySegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 6.0, 3000, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 900]);

    expect(scorerVerdict($user, $row)['intent']['verdict'])->toBe($expected);
})->with([
    'config default zones keep pace first' => [null, IntentVerdict::Hit],
    'observed zones are capped' => ['observed', IntentVerdict::TooHard],
    'manual zones are capped' => ['manual', IntentVerdict::TooHard],
]);

it('grades a mildly eased quality day against the slowed pace it was shown', function (string $bestPace, IntentVerdict $expected): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo, 'clamped_km' => 8.0]);
    $slowed = [
        new SessionSegment(SegmentKey::Warmup, 10.0, 'Z2', PaceBand::Easy, 400, 1.5)->toArray(),
        new SessionSegment(SegmentKey::Main, 20.0, 'Z4', PaceBand::Threshold, 319, 3.8)->toArray(),
    ];
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => $slowed, 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => 'ease this one',
    ]);
    shownRun($user, '2026-08-05', 8.0, 2800, ['best_20min_pace' => $bestPace]);

    $intent = scorerVerdict($user, $row)['intent'];

    expect($intent['verdict'])->toBe($expected)
        ->and($intent['evidence'])->toMatchArray(['target_pace_sec' => 319, 'concern' => 'none'])
        ->not->toHaveKey('eased_from');
})->with([
    'inside the slowed tolerance, outside the original' => ['5:27', IntentVerdict::Hit],
    'outside the slowed tolerance' => ['5:31', IntentVerdict::Missed],
]);

it('marks a shown quality session graded on complete evidence as eligible to teach progression', function (array $summary, IntentVerdict $expected): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 8.0, 2800, $summary);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe($expected)
        ->and($verdict['intent']['evidence'])->toMatchArray(['concern' => 'none', 'quality_progression' => 'eligible']);
})->with([
    'controlled hit' => [controlledTempoSummary(), IntentVerdict::Hit],
    'complete miss' => [['best_20min_pace' => '6:35'], IntentVerdict::Missed],
    'excessive' => [['best_20min_pace' => '4:40'], IntentVerdict::TooHard],
]);

it('keeps a shown quality day with unknown intent informational and out of progression', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 8.0, 2800);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Done)
        ->and($verdict['distance_score'])->toBe(100)
        ->and($verdict['intent']['evidence'])->not->toHaveKey('quality_progression');
});

it('keeps verified distance and an unknown intent when no advice was shown', function (): void {
    $user = User::factory()->create();
    scorerPaces($user, '2026-08-05');
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    $askedKm = (float) scorerVerdict($user, $row)['prescribed_km'];
    scorerPacedRun($user, '2026-08-05', $askedKm, 450, everyWindowAt(450));

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($verdict['intent']['evidence'])->toBe(['advice_history' => 'unknown'])
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Done)
        ->and($verdict['distance_score'])->toBe(100);
});

it('judges a multi-activity day on its longest run and gives unknown intent when no recording covers the block', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Tempo]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 3.5, 900, ['best_10min_pace' => '5:00', 'time_in_zone_min' => ['Z1' => 1, 'Z2' => 2, 'Z3' => 2, 'Z4' => 10, 'Z5' => 0]]);
    shownRun($user, '2026-08-05', 3.0, 720, ['time_in_zone_min' => ['Z1' => 1, 'Z2' => 2, 'Z3' => 2, 'Z4' => 8, 'Z5' => 0]], '17:00:00');

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($verdict['intent']['evidence'])->toMatchArray(['stimulus_family' => 'tempo', 'stimulus_minutes' => 18.0])
        ->not->toHaveKey('quality_progression');
});

it('rewrites revised intent evidence at an equal distance score without touching the earned score', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => 100,
        'distance_score' => 100,
        'prescribed_km' => 8.0,
        'intent_verdict' => IntentVerdict::Unknown,
        'intent_evidence' => ['advice_history' => 'unknown'],
    ]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 8.0, 2800, controlledTempoSummary());

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-20'));
    $row->refresh();

    expect($row->intent_verdict)->toBe(IntentVerdict::Hit)
        ->and($row->intent_evidence)->toMatchArray(['advice_history' => 'shown', 'quality_progression' => 'eligible'])
        ->and($row->compliance_score)->toBe(100)
        ->and($row->status)->toBe(PlannedSessionStatus::Done);
});

it('rewrites intent evidence when a smaller recomputed score would not replace the earned one', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Overreached,
        'compliance_score' => 140,
        'distance_score' => 140,
        'prescribed_km' => 8.0,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['advice_history' => 'shown', 'stimulus_family' => 'tempo', 'quality_progression' => 'eligible'],
    ]);
    showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 8.0, 2800, ['best_20min_pace' => '6:35']);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-20'));
    $row->refresh();

    expect($row->intent_verdict)->toBe(IntentVerdict::Missed)
        ->and($row->compliance_score)->toBe(140)
        ->and($row->status)->toBe(PlannedSessionStatus::Overreached);
});

it('clears stale intent evidence once the day has no credited run left, keeping the earned score', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Done,
        'compliance_score' => 100,
        'distance_score' => 100,
        'intent_verdict' => IntentVerdict::Hit,
        'intent_evidence' => ['advice_history' => 'shown', 'stimulus_family' => 'tempo', 'quality_progression' => 'eligible'],
    ]);

    app(ComplianceScorer::class)->creditIfEarned($user, Carbon::parse('2026-08-05'), Carbon::parse('2026-08-20'));
    $row->refresh();

    expect($row->intent_verdict)->toBeNull()
        ->and($row->intent_evidence)->toBeNull()
        ->and($row->compliance_score)->toBe(100);
});

it('does not read an easy run as the original completed when the eased session was a marathon-paced long run', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', ['session_type' => SessionType::Long, 'clamped_km' => 6.4]);
    $marathonLong = [new SessionSegment(SegmentKey::Main, 90.0, 'Z3', PaceBand::Marathon, 340, 16.0)->toArray()];
    showAdvice($user, '2026-08-05', ['session_type' => 'long', 'phase' => 'peak', 'hard_minutes' => 90, 'distance_km' => 16.0, 'reason' => null, 'segments' => $marathonLong], shownEasyEffective());
    shownRun($user, '2026-08-05', 6.4, 2580);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['intent']['evidence'])->toMatchArray(['eased_from' => 'long', 'stimulus_family' => 'easy'])
        ->not->toHaveKeys(['original_completed', 'quality_progression']);
});

it('grades a time trial on its distance and the trial gate, never on its pace segments', function (int $trialSecPerKm, IntentVerdict $intent, PlannedSessionStatus $status, string $gate): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => ['kind' => 'time_trial', 'distance_m' => 5_000, 'aim_time_sec' => 1_500, 'retry' => 0],
    ]);
    scorerPacedRun($user, '2026-08-05', 2.0, 400);
    scorerPacedRun($user, '2026-08-05', 5.0, $trialSecPerKm, everyWindowAt($trialSecPerKm));

    $verdict = scorerVerdict($user, $row);

    expect($verdict['prescribed_km'])->toBe(5.0)
        ->and($verdict['intent']['verdict'])->toBe($intent)
        ->and($verdict['intent']['evidence'])->toMatchArray(['time_trial' => $gate, 'effective_type' => 'interval'])
        ->and($verdict['status'])->toBe($status);
})->with([
    'much faster than the aim' => [250, IntentVerdict::Hit, PlannedSessionStatus::Done, 'pace'],
    'slower than the aim and its slack' => [330, IntentVerdict::Missed, PlannedSessionStatus::Partial, 'not_passed'],
]);

it('grades a warmup and trial recorded as one run by its trial split, crediting no more than the trial', function (): void {
    $user = User::factory()->create();
    $row = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => ['kind' => 'time_trial', 'distance_m' => 5_000, 'aim_time_sec' => 1_500, 'retry' => 0],
    ]);
    $perKm = [
        ['km' => 1, 'pace' => '6:40', 'elapsed_sec' => 400, 'distance_m' => 1000],
        ['km' => 2, 'pace' => '6:40', 'elapsed_sec' => 400, 'distance_m' => 1000],
        ...array_map(static fn (int $km): array => ['km' => $km, 'pace' => '4:50', 'elapsed_sec' => 290, 'distance_m' => 1000], range(3, 7)),
    ];
    scorerPacedRun($user, '2026-08-05', 7.0, 321, ['per_km' => $perKm]);

    $verdict = scorerVerdict($user, $row);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['intent']['evidence'])->toMatchArray(['time_trial' => 'pace', 'time_trial_read' => 'split'])
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Done);
});

/** @return array{PlannedSession, PlannedSession} the emptied Tuesday and the made-up Wednesday */
function madeUpPair(User $user, SessionType $movedType): array
{
    foreach (['2026-06-28', '2026-07-05', '2026-07-12', '2026-07-19', '2026-07-26', '2026-08-02'] as $weekEnding) {
        WeeklySnapshot::factory()->for($user)->create(['week_ending' => $weekEnding, 'distance_km' => 50.0, 'runs' => 5]);
    }
    scorerRun($user, '2026-07-26', 16.0);

    $vacated = scorerDay($user, '2026-08-04', ['session_type' => SessionType::Rest, 'made_up_on' => '2026-08-05']);
    $target = scorerDay($user, '2026-08-05', ['session_type' => $movedType, 'made_up_from_id' => $vacated->id]);

    return [$vacated, $target];
}

it('grades a made-up day against the moved session, not the rest it was shown that morning', function (): void {
    $user = User::factory()->create();
    $paces = scorerPaces($user, '2026-08-05');
    [, $target] = madeUpPair($user, SessionType::Easy);
    showAdvice($user, '2026-08-05', ['session_type' => 'rest'], [
        'session_type' => 'rest', 'distance_km' => 0.0, 'segments' => [], 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    $askedKm = (float) scorerVerdict($user, $target)['prescribed_km'];
    shownRun($user, '2026-08-05', $askedKm, (int) round($askedKm * $paces['easy']), everyWindowAt($paces['easy']));

    $verdict = scorerVerdict($user, $target);

    expect($askedKm)->toBeGreaterThan(0.0)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Done)
        ->and($verdict['distance_score'])->toBe(100)
        ->and($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['intent']['evidence'])->toMatchArray(['advice_history' => 'declared_after_run', 'effective_type' => 'easy'])
        ->and($verdict['intent']['evidence'])->not->toHaveKey('quality_progression');
});

it('keeps a made-up quality session out of progression even when it hit', function (): void {
    $user = User::factory()->create();
    $paces = scorerPaces($user, '2026-08-05');
    [, $target] = madeUpPair($user, SessionType::Tempo);
    $askedKm = (float) scorerVerdict($user, $target)['prescribed_km'];
    scorerPacedRun($user, '2026-08-05', $askedKm, $paces['threshold'], everyWindowAt($paces['threshold']) + ['time_in_zone_min' => ['Z1' => 0, 'Z2' => 10, 'Z3' => 5, 'Z4' => 30, 'Z5' => 20]]);

    $verdict = scorerVerdict($user, $target);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['intent']['evidence'])->toMatchArray(['advice_history' => 'declared_after_run', 'effective_type' => 'tempo'])
        ->and($verdict['intent']['evidence'])->not->toHaveKey('quality_progression');
});

it('grades a session made up onto a day still ahead on the advice shown before its run', function (): void {
    $user = User::factory()->create();
    [, $target] = madeUpPair($user, SessionType::Tempo);
    $revision = showAdvice($user, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-05', 8.0, 2800, controlledTempoSummary());

    $verdict = scorerVerdict($user, $target);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['intent']['evidence'])->toMatchArray([
            'advice_history' => 'shown',
            'recommendation_revision_id' => $revision->id,
            'quality_progression' => 'eligible',
        ]);
});

it('keeps a make-up declared after the run when the moved session was shown only after the day\'s first run', function (array $runTimes): void {
    $user = User::factory()->create();
    [, $target] = madeUpPair($user, SessionType::Tempo);
    showAdvice($user, '2026-08-05', ['session_type' => 'rest'], [
        'session_type' => 'rest', 'distance_km' => 0.0, 'segments' => [], 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    $tempo = app(RecommendationHistory::class)->record($user->id, '2026-08-05', shownTempoOriginal(), [
        'session_type' => 'tempo', 'distance_km' => 8.0, 'segments' => shownTempoSegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    RecommendationView::query()->create([
        'recommendation_revision_id' => $tempo->id,
        'observation_id' => (string) Str::uuid(),
        'shown_at' => Carbon::parse('2026-08-05 09:00:00', 'UTC'),
    ]);
    foreach ($runTimes as [$km, $time]) {
        shownRun($user, '2026-08-05', $km, (int) round($km * 350), controlledTempoSummary(), $time);
    }

    $verdict = scorerVerdict($user, $target);

    expect($verdict['intent']['evidence'])->toMatchArray(['advice_history' => 'declared_after_run', 'effective_type' => 'tempo'])
        ->and($verdict['intent']['evidence'])->not->toHaveKeys(['quality_progression', 'recommendation_revision_id']);
})->with([
    'run before the move was shown' => [[[8.0, '06:00:00']]],
    'a short run before, the long one after' => [[[3.0, '06:00:00'], [8.0, '18:00:00']]],
]);

it('judges a made-up time trial on its gate and tags it declared after the run', function (): void {
    $user = User::factory()->create();
    $vacated = scorerDay($user, '2026-08-04', ['session_type' => SessionType::Rest, 'made_up_on' => '2026-08-05']);
    $target = scorerDay($user, '2026-08-05', [
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => ['kind' => 'time_trial', 'distance_m' => 5_000, 'aim_time_sec' => 1_500, 'retry' => 0],
        'made_up_from_id' => $vacated->id,
    ]);
    scorerPacedRun($user, '2026-08-05', 5.0, 250, everyWindowAt(250));

    $verdict = scorerVerdict($user, $target);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit)
        ->and($verdict['intent']['evidence'])->toMatchArray(['time_trial' => 'pace', 'advice_history' => 'declared_after_run'])
        ->and($verdict['intent']['evidence'])->not->toHaveKey('quality_progression');
});

it('grades a missed tempo made up on an easy run as missed on intent', function (): void {
    $user = User::factory()->create();
    $paces = scorerPaces($user, '2026-08-05');
    [, $target] = madeUpPair($user, SessionType::Tempo);
    $askedKm = (float) scorerVerdict($user, $target)['prescribed_km'];
    scorerPacedRun($user, '2026-08-05', $askedKm, $paces['easy'], everyWindowAt($paces['easy']));

    $verdict = scorerVerdict($user, $target);

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Missed)
        ->and($verdict['status'])->toBe(PlannedSessionStatus::Partial);
});

it('grades the day a make-up emptied as rest, whatever it was shown or run', function (): void {
    $user = User::factory()->create();
    [$vacated] = madeUpPair($user, SessionType::Easy);
    showAdvice($user, '2026-08-04', ['session_type' => 'easy'], [
        'session_type' => 'easy', 'distance_km' => 6.0, 'segments' => shownEasySegments(), 'paces' => SHOWN_PACES, 'skipped' => false, 'reason' => null,
    ]);
    shownRun($user, '2026-08-04', 1.5, 540);

    $verdict = scorerVerdict($user, $vacated);

    expect($verdict['status'])->toBe(PlannedSessionStatus::Done)
        ->and($verdict['distance_score'])->toBeNull()
        ->and($verdict['intent'])->toBeNull();
});
