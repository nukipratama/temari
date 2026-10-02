<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Services\Run\Plan\SessionIntentJudge;
use App\Services\Run\Plan\SessionSegment;

const JUDGE_PACES = ['easy' => 400, 'marathon' => 340, 'threshold' => 310, 'interval' => 285];

/** @param  array<string, mixed>  $summary */
function judgeRun(float $km, int $movingSec, array $summary = []): ActivityDetail
{
    return new ActivityDetail()->forceFill([
        'distance' => $km * 1000,
        'moving_time' => $movingSec,
        'elapsed_time' => $movingSec,
        'stream_summary' => $summary,
    ]);
}

/** @return list<SessionSegment> */
function tempoDay(float $blockMinutes = 20.0, PaceBand $band = PaceBand::Threshold): array
{
    return [
        new SessionSegment(SegmentKey::Warmup, 10.0, 'Z2', PaceBand::Easy, 400, 1.5),
        new SessionSegment(SegmentKey::Main, $blockMinutes, $band === PaceBand::Threshold ? 'Z4' : 'Z3', $band, JUDGE_PACES[$band->value], 4.0),
    ];
}

/** @return list<SessionSegment> */
function intervalDay(int $reps = 4, float $repMinutes = 3.0): array
{
    $segments = [new SessionSegment(SegmentKey::Warmup, 12.0, 'Z2', PaceBand::Easy, 400, 1.8)];
    for ($i = 0; $i < $reps; $i++) {
        $segments[] = new SessionSegment(SegmentKey::Interval, $repMinutes, 'Z5', PaceBand::Interval, 285, 0.6);
        if ($i < $reps - 1) {
            $segments[] = new SessionSegment(SegmentKey::Recovery, 2.0, 'Z2', PaceBand::Easy, 400, 0.3);
        }
    }

    return $segments;
}

/** @return list<SessionSegment> */
function easyDay(PaceBand $band = PaceBand::Easy): array
{
    return [new SessionSegment(SegmentKey::Main, 40.0, $band === PaceBand::Easy ? 'Z2' : 'Z3', $band, JUDGE_PACES[$band->value], 6.0)];
}

/**
 * @param  list<array{0: int, 1: int}>  $laps  [distance_m, elapsed_sec]
 * @return list<array<string, int|string>>
 */
function lapRows(array $laps): array
{
    return array_map(
        static fn (array $lap, int $i): array => ['lap' => $i + 1, 'distance_m' => $lap[0], 'elapsed_sec' => $lap[1], 'pace' => '0:00'],
        $laps,
        array_keys($laps),
    );
}

it('reads a tempo held within tolerance of threshold for as long as the block as hit', function (): void {
    $run = judgeRun(8.0, 2800, ['best_20min_pace' => '5:18']);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reading['evidence'])->toMatchArray([
            'basis' => 'pace',
            'window' => '20min',
            'window_pace_sec' => 318,
            'target_pace_sec' => 310,
        ]);
});

it('measures a block that falls between windows on the longest window that fits inside it', function (): void {
    $run = judgeRun(8.0, 2800, ['best_20min_pace' => '5:10', 'best_30min_pace' => '6:00']);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(blockMinutes: 22.0), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reading['evidence']['window'])->toBe('20min');
});

it('cannot read a window well short of the block as a hit', function (): void {
    $run = judgeRun(8.0, 2800, ['best_20min_pace' => '5:10']);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(blockMinutes: 26.0), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($reading['evidence'])->toMatchArray(['window' => '20min', 'stimulus_family' => 'unknown', 'stimulus_source' => 'none']);
});

it('lets heart rate decide a block the pace windows cannot cover', function (): void {
    $zones = ['Z1' => 2, 'Z2' => 12, 'Z3' => 6, 'Z4' => 24, 'Z5' => 4];
    $run = judgeRun(8.0, 2800, ['best_20min_pace' => '5:10', 'time_in_zone_min' => $zones]);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(blockMinutes: 26.0), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reading['evidence'])->toMatchArray(['basis' => 'heart_rate', 'stimulus_source' => 'heart_rate', 'stimulus_minutes' => 28.0]);
});

it('reads a tempo faster than target by more than five percent as too hard, not rewarded', function (): void {
    $run = judgeRun(8.0, 2800, ['best_20min_pace' => '4:50']);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($reading['evidence'])->toMatchArray(['basis' => 'pace', 'control' => 'excessive', 'stimulus_family' => 'tempo', 'stimulus_minutes' => 20.0, 'stimulus_source' => 'window']);
});

it('keeps a tempo within five percent faster than target controlled', function (): void {
    $run = judgeRun(8.0, 2800, ['best_20min_pace' => '5:00']);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reading['evidence'])->toMatchArray(['control' => 'controlled', 'stimulus_family' => 'tempo', 'stimulus_minutes' => 20.0]);
});

it('cannot judge a tempo split across recordings when the longest one cannot cover the block', function (): void {
    $longest = judgeRun(3.0, 900, ['best_10min_pace' => '5:00', 'time_in_zone_min' => ['Z1' => 1, 'Z2' => 2, 'Z3' => 2, 'Z4' => 10, 'Z5' => 0]]);
    $other = judgeRun(2.0, 600, ['time_in_zone_min' => ['Z1' => 1, 'Z2' => 2, 'Z3' => 2, 'Z4' => 5, 'Z5' => 0]]);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$other, $longest]);

    expect($reading['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($reading['evidence'])->toMatchArray(['stimulus_family' => 'tempo', 'stimulus_minutes' => 15.0, 'stimulus_source' => 'heart_rate']);
});

it('still reads one short recording as missed when heart rate is complete', function (): void {
    $run = judgeRun(3.0, 900, ['time_in_zone_min' => ['Z1' => 1, 'Z2' => 2, 'Z3' => 2, 'Z4' => 10, 'Z5' => 0]]);

    expect(SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$run])['verdict'])->toBe(IntentVerdict::Missed);
});

it('reads a tempo run all easy as missed', function (): void {
    $run = judgeRun(6.4, 2580, ['best_20min_pace' => '6:35']);

    expect(SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$run])['verdict'])
        ->toBe(IntentVerdict::Missed);
});

it('rescues a slow tempo when heart rate held the threshold zone for the block', function (): void {
    $run = judgeRun(7.0, 2700, [
        'best_20min_pace' => '5:40',
        'time_in_zone_min' => ['Z1' => 2, 'Z2' => 12, 'Z3' => 6, 'Z4' => 18, 'Z5' => 4],
    ]);

    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reading['evidence'])->toMatchArray(['basis' => 'heart_rate', 'zone' => 'Z4', 'zone_minutes' => 22.0]);
});

it('keeps a slow tempo missed when heart rate never reached the zone', function (): void {
    $run = judgeRun(7.0, 2700, [
        'best_20min_pace' => '5:40',
        'time_in_zone_min' => ['Z1' => 2, 'Z2' => 30, 'Z3' => 10, 'Z4' => 3, 'Z5' => 0],
    ]);

    expect(SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$run])['verdict'])
        ->toBe(IntentVerdict::Missed);
});

it('judges a tempo on the day\'s credited run, its longest', function (): void {
    $short = judgeRun(2.0, 600, ['best_10min_pace' => '5:00']);
    $long = judgeRun(7.0, 2700, ['best_20min_pace' => '6:40']);

    expect(SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [$short, $long])['verdict'])
        ->toBe(IntentVerdict::Missed);
});

it('cannot judge a tempo with neither a pace window nor heart rate', function (): void {
    expect(SessionIntentJudge::judge(SessionType::Tempo, tempoDay(), JUDGE_PACES, [judgeRun(6.0, 2400)])['verdict'])
        ->toBe(IntentVerdict::Unknown);
});

it('reads intervals with all but one rep at pace as hit', function (): void {
    $laps = lapRows([[2000, 800], [630, 180], [300, 130], [620, 180], [300, 130], [640, 180], [300, 130], [560, 180], [1000, 400]]);
    $run = judgeRun(6.6, 2300, ['laps' => $laps]);

    $reading = SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reading['evidence'])->toMatchArray(['basis' => 'laps', 'reps_prescribed' => 4, 'reps_needed' => 3, 'reps_at_pace' => 3]);
});

it('reads intervals two reps short as missed', function (): void {
    $laps = lapRows([[2000, 800], [630, 180], [300, 130], [560, 180], [300, 130], [550, 180], [300, 130], [620, 180], [1000, 400]]);
    $run = judgeRun(6.6, 2300, ['laps' => $laps]);

    expect(SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [$run])['verdict'])
        ->toBe(IntentVerdict::Missed);
});

it('cannot judge intervals on auto-km laps with no other signal', function (): void {
    $laps = lapRows([[1000, 400], [1000, 300], [1000, 390], [1000, 295], [600, 240]]);
    $run = judgeRun(4.6, 1625, ['laps' => $laps]);

    expect(SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [$run])['verdict'])
        ->toBe(IntentVerdict::Unknown);
});

it('falls back to a rep-length window when the laps are just the kilometres', function (): void {
    $laps = lapRows([[1000, 400], [1000, 300], [1000, 390], [1000, 295], [600, 240]]);

    $fast = SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [judgeRun(4.6, 1625, ['laps' => $laps, 'best_3min_pace' => '4:50'])]);
    $slow = SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [judgeRun(4.6, 1625, ['laps' => $laps, 'best_3min_pace' => '5:30'])]);

    expect($fast['verdict'])->toBe(IntentVerdict::Hit)
        ->and($fast['evidence']['basis'])->toBe('window')
        ->and($slow['verdict'])->toBe(IntentVerdict::Missed);
});

it('rescues short intervals when heart rate reached the rep zone for the reps needed', function (): void {
    $run = judgeRun(4.6, 1625, [
        'best_3min_pace' => '5:30',
        'time_in_zone_min' => ['Z1' => 5, 'Z2' => 10, 'Z3' => 5, 'Z4' => 4, 'Z5' => 9],
    ]);

    expect(SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [$run])['verdict'])
        ->toBe(IntentVerdict::Hit);
});

it('reads an easy run faster than marathon pace as too hard', function (): void {
    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [judgeRun(6.0, 1980)]);

    expect($reading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($reading['evidence'])->toMatchArray(['basis' => 'pace', 'pace_sec' => 330, 'ceiling_pace_sec' => 340]);
});

it('reads an easy run slower than marathon pace as hit', function (): void {
    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [judgeRun(5.3, 2136)])['verdict'])
        ->toBe(IntentVerdict::Hit);
});

it('judges an easy day on every run it held, hill-adjusted where it can', function (): void {
    // 5 km in 1650 s reads 5:30 raw, but the course gave back 5:50 once grade is taken out.
    $downhill = judgeRun(5.0, 1650, ['gap_pace' => '5:50']);
    $other = judgeRun(3.0, 1000);

    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$downhill, $other])['verdict'])
        ->toBe(IntentVerdict::Hit);
});

it('lets heart rate rescue a fast easy run that stayed in its zone', function (): void {
    $run = judgeRun(6.0, 1980, ['time_in_zone_min' => ['Z1' => 8, 'Z2' => 22, 'Z3' => 3, 'Z4' => 0, 'Z5' => 0]]);

    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$run])['verdict'])
        ->toBe(IntentVerdict::Hit);
});

it('lets heart rate confirm a fast easy run was too hard', function (): void {
    $run = judgeRun(6.0, 1980, ['time_in_zone_min' => ['Z1' => 2, 'Z2' => 10, 'Z3' => 15, 'Z4' => 6, 'Z5' => 0]]);

    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($reading['evidence']['basis'])->toBe('heart_rate');
});

it('gives a long run prescribed at marathon pace the same tolerance a tempo gets', function (): void {
    $onPace = judgeRun(20.0, 6700);
    $wellUnder = judgeRun(20.0, 6400);

    expect(SessionIntentJudge::judge(SessionType::Long, easyDay(PaceBand::Marathon), JUDGE_PACES, [$onPace])['verdict'])->toBe(IntentVerdict::Hit)
        ->and(SessionIntentJudge::judge(SessionType::Long, easyDay(PaceBand::Marathon), JUDGE_PACES, [$wellUnder])['verdict'])->toBe(IntentVerdict::TooHard);
});

it('cannot judge without paces, without runs, or on a rest or race day', function (): void {
    $run = judgeRun(6.0, 1980);

    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), null, [$run])['verdict'])->toBe(IntentVerdict::Unknown)
        ->and(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [])['verdict'])->toBe(IntentVerdict::Unknown)
        ->and(SessionIntentJudge::judge(SessionType::Rest, [], JUDGE_PACES, [$run])['verdict'])->toBe(IntentVerdict::Unknown)
        ->and(SessionIntentJudge::judge(SessionType::Race, easyDay(), JUDGE_PACES, [$run])['verdict'])->toBe(IntentVerdict::Unknown)
        ->and(SessionIntentJudge::judge(SessionType::Tempo, [], JUDGE_PACES, [$run])['verdict'])->toBe(IntentVerdict::Unknown)
        ->and(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [judgeRun(0.0, 0)])['verdict'])->toBe(IntentVerdict::Unknown);
});

it('marks a single marathon-pace long segment as graded against the marathon limit', function (): void {
    $reading = SessionIntentJudge::judge(SessionType::Long, easyDay(PaceBand::Marathon), JUDGE_PACES, [judgeRun(6.0, 1980)]);

    expect($reading['evidence'])->toMatchArray(['limit' => 'marathon', 'ceiling_pace_sec' => 330])
        ->and(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [judgeRun(6.0, 1980)])['evidence'])->not->toHaveKey('limit');
});

it('reads intervals run far faster than the rep pace as too hard', function (): void {
    $laps = lapRows([[2000, 800], [700, 180], [300, 130], [710, 180], [300, 130], [720, 180], [300, 130], [700, 180], [1000, 400]]);

    $reading = SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [judgeRun(6.9, 2300, ['laps' => $laps])]);

    expect($reading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($reading['evidence'])->toMatchArray(['basis' => 'laps', 'control' => 'excessive', 'stimulus_family' => 'interval', 'stimulus_minutes' => 12.0, 'stimulus_source' => 'laps']);
});

it('does not count laps far shorter than the prescribed rep as reps', function (): void {
    $laps = lapRows([[2000, 800], [210, 60], [300, 130], [210, 60], [300, 130], [210, 60], [300, 130], [210, 60], [1000, 400]]);

    $reading = SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [judgeRun(4.7, 1700, ['laps' => $laps])]);

    expect($reading['verdict'])->toBe(IntentVerdict::Missed)
        ->and($reading['evidence'])->toMatchArray(['reps_at_pace' => 0]);
});

it('cannot read a short rep window as covering a longer rep', function (): void {
    $laps = lapRows([[1000, 400], [1000, 300], [1000, 390], [1000, 295], [600, 240]]);

    $reading = SessionIntentJudge::judge(SessionType::Interval, intervalDay(repMinutes: 4.0), JUDGE_PACES, [judgeRun(4.6, 1625, ['laps' => $laps, 'best_3min_pace' => '4:50'])]);

    expect($reading['verdict'])->toBe(IntentVerdict::Unknown);
});

it('records an easy day that stayed easy as easy stimulus, however fast', function (): void {
    $run = judgeRun(6.0, 1980, ['time_in_zone_min' => ['Z1' => 8, 'Z2' => 22, 'Z3' => 3, 'Z4' => 0, 'Z5' => 0]]);

    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$run])['evidence'])
        ->toMatchArray(['stimulus_family' => 'easy', 'stimulus_source' => 'heart_rate']);
});

it('records self-added hard work on an easy day with the hard minutes summed across the day', function (): void {
    $first = judgeRun(6.0, 1980, ['time_in_zone_min' => ['Z1' => 2, 'Z2' => 10, 'Z3' => 15, 'Z4' => 6, 'Z5' => 0]]);
    $second = judgeRun(2.0, 700, ['time_in_zone_min' => ['Z1' => 0, 'Z2' => 5, 'Z3' => 5, 'Z4' => 2, 'Z5' => 0]]);

    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$first, $second]);

    expect($reading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($reading['evidence'])->toMatchArray(['stimulus_family' => 'hard', 'stimulus_minutes' => 28.0, 'stimulus_source' => 'heart_rate']);
});

it('records no hard minutes when a too-fast easy run carries no heart rate', function (): void {
    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [judgeRun(6.0, 1980)]);

    expect($reading['evidence'])->toMatchArray(['stimulus_family' => 'hard', 'stimulus_source' => 'pace'])
        ->not->toHaveKey('stimulus_minutes');
});
