<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Services\Run\Plan\IntentOutcome;

it('words every verdict as a clause with no enum token in it', function (IntentVerdict $verdict, array $evidence, string $expected): void {
    $outcome = IntentOutcome::outcome($verdict, $evidence);

    expect($outcome)->toBe($expected)
        ->not->toContain('_')
        ->not->toMatch('/\b(hit|too hard|unknown)\b/');
})->with([
    'easy day hit' => [IntentVerdict::Hit, ['pace_sec' => 449, 'ceiling_pace_sec' => 408, 'basis' => 'pace'], 'it stayed at the easy effort the day asked for'],
    'tempo block hit' => [IntentVerdict::Hit, ['block_minutes' => 20, 'target_pace_sec' => 300], 'the hard block got done at the effort it asked for'],
    'reps hit' => [IntentVerdict::Hit, ['reps_prescribed' => 5, 'target_pace_sec' => 270], 'the reps got done at the effort they asked for'],
    'hit with no evidence' => [IntentVerdict::Hit, [], 'the session did the job it was written for'],
    'tempo missed' => [IntentVerdict::Missed, ['block_minutes' => 20], 'the distance is there, but the hard effort the day asked for never showed up'],
    'reps missed' => [IntentVerdict::Missed, ['reps_prescribed' => 5], 'the distance is there, but the reps never reached the effort they asked for'],
    'too hard' => [IntentVerdict::TooHard, ['pace_sec' => 380, 'ceiling_pace_sec' => 408], 'it ran harder than the easy effort the day asked for'],
    'unknown' => [IntentVerdict::Unknown, [], "this run's data can't tell how the effort went"],
]);

it('quotes the moving pace on an easy day that stayed easy, with the hill-adjusted one named as such', function (): void {
    $detail = IntentOutcome::detail(IntentVerdict::Hit, ['pace_sec' => 449, 'ceiling_pace_sec' => 408, 'basis' => 'pace'], 442);

    expect($detail)->toBe('averaged 7:22/km; effort-adjusted for hills that is 7:29/km')
        ->not->toContain('6:48');
});

it('quotes only the moving pace when the hill-adjusted one barely differs', function (): void {
    $detail = IntentOutcome::detail(IntentVerdict::Hit, ['pace_sec' => 419, 'ceiling_pace_sec' => 408, 'basis' => 'pace'], 417);

    expect($detail)->toBe('averaged 6:57/km');
});

it('falls back to the graded pace when no moving pace is known', function (): void {
    expect(IntentOutcome::averaged(419, null))->toBe('averaged 6:59/km');
});

it('names the hill-adjusted pace once the gap reaches the threshold, and not a second before', function (): void {
    expect(IntentOutcome::averaged(400, 400 - IntentOutcome::EFFORT_ADJUSTED_MIN_GAP_SEC))->toContain('effort-adjusted')
        ->and(IntentOutcome::averaged(400, 400 - IntentOutcome::EFFORT_ADJUSTED_MIN_GAP_SEC + 1))->not->toContain('effort-adjusted');
});

it('names the hill-adjusted pace whenever the moving pace alone sits on the other side of the limit', function (): void {
    $detail = IntentOutcome::detail(IntentVerdict::TooHard, ['pace_sec' => 405, 'ceiling_pace_sec' => 408, 'basis' => 'pace'], 409);

    expect($detail)->toBe('averaged 6:49/km; effort-adjusted for hills that is 6:45/km, quicker than the easy limit of 6:48/km');
});

it('states a too-hard easy day against the limit in words', function (): void {
    expect(IntentOutcome::detail(IntentVerdict::TooHard, ['pace_sec' => 380, 'ceiling_pace_sec' => 408, 'basis' => 'pace'], 381))
        ->toBe('averaged 6:21/km, quicker than the easy limit of 6:48/km');
});

it('reads a heart-rate verdict on a steady day without implying the pace decided it', function (): void {
    $evidence = ['pace_sec' => 395, 'ceiling_pace_sec' => 408, 'basis' => 'heart_rate', 'zone' => 'Z2'];

    expect(IntentOutcome::detail(IntentVerdict::TooHard, $evidence + ['above_zone_pct' => 64], 396))
        ->toBe('averaged 6:36/km, and 64% of the run sat above Z2')
        ->and(IntentOutcome::detail(IntentVerdict::Hit, $evidence + ['above_zone_pct' => 12], 396))
        ->toBe('averaged 6:36/km, but heart rate kept it easy: only 12% of the run went above Z2');
});

it('reads a tempo block on pace, and one rescued or sunk by heart rate', function (): void {
    $base = ['block_minutes' => 20.0, 'target_pace_sec' => 300, 'tolerance_sec' => 10, 'window' => '20min'];

    expect(IntentOutcome::detail(IntentVerdict::Hit, $base + ['window_pace_sec' => 305, 'basis' => 'pace'], null))
        ->toBe('best 20-minute stretch averaged 5:05/km, on the 5:00/km target pace')
        ->and(IntentOutcome::detail(IntentVerdict::Hit, $base + ['window_pace_sec' => 320, 'basis' => 'heart_rate', 'zone' => 'Z4', 'zone_minutes' => 22.4], null))
        ->toBe('best 20-minute stretch averaged 5:20/km, slower than the 5:00/km target pace, but heart rate spent 22 minutes in Z4 or above, enough for the 20 minutes asked')
        ->and(IntentOutcome::detail(IntentVerdict::Missed, $base + ['window_pace_sec' => 320, 'basis' => 'heart_rate', 'zone' => 'Z4', 'zone_minutes' => 8.0], null))
        ->toBe('best 20-minute stretch averaged 5:20/km, slower than the 5:00/km target pace, and heart rate spent only 8 of the 20 minutes asked in Z4 or above')
        ->and(IntentOutcome::detail(IntentVerdict::Missed, $base + ['window_pace_sec' => 320, 'basis' => 'pace'], null))
        ->toBe('best 20-minute stretch averaged 5:20/km, slower than the 5:00/km target pace');
});

it('reads interval reps from laps and from the best stretch', function (): void {
    $base = ['reps_prescribed' => 5, 'reps_needed' => 4, 'rep_minutes' => 3.0, 'target_pace_sec' => 270, 'tolerance_sec' => 10];

    expect(IntentOutcome::detail(IntentVerdict::Hit, $base + ['reps_at_pace' => 4, 'basis' => 'laps'], null))
        ->toBe('4 of 5 reps landed on the 4:30/km rep pace')
        ->and(IntentOutcome::detail(IntentVerdict::Missed, $base + ['reps_at_pace' => 2, 'basis' => 'heart_rate', 'zone' => 'Z5', 'zone_minutes' => 4.0], null))
        ->toBe('only 2 of 5 reps landed on the 4:30/km rep pace, and heart rate spent only 4 of the 12 minutes asked in Z5 or above')
        ->and(IntentOutcome::detail(IntentVerdict::Hit, $base + ['window' => '30s', 'window_pace_sec' => 265, 'basis' => 'window'], null))
        ->toBe('best 30-second stretch averaged 4:25/km, on the 4:30/km rep pace');
});

it('has nothing to add for an unknown verdict or evidence it cannot read', function (): void {
    expect(IntentOutcome::detail(IntentVerdict::Unknown, ['pace_sec' => 400, 'ceiling_pace_sec' => 408], 400))->toBeNull()
        ->and(IntentOutcome::detail(IntentVerdict::Hit, [], 400))->toBeNull()
        ->and(IntentOutcome::detail(IntentVerdict::Hit, ['ceiling_pace_sec' => 408], 400))->toBeNull()
        ->and(IntentOutcome::detail(IntentVerdict::Missed, ['block_minutes' => 20.0, 'basis' => 'pace'], null))->toBeNull();
});
