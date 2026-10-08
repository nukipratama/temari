<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Enums\PlanPhase;
use App\Services\Run\Plan\IntensityPrescription;
use App\Services\Run\Plan\IntensityPrescriptionResolver;
use App\Services\Run\Plan\SegmentGenerator;
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
function tempoBlocksDay(int $blocks = 3, float $blockMinutes = 10.0): array
{
    $segments = [new SessionSegment(SegmentKey::Warmup, 10.0, 'Z2', PaceBand::Easy, 400, 1.5)];
    for ($i = 0; $i < $blocks; $i++) {
        $segments[] = new SessionSegment(SegmentKey::Main, $blockMinutes, 'Z4', PaceBand::Threshold, JUDGE_PACES['threshold'], 2.0);
        if ($i < $blocks - 1) {
            $segments[] = new SessionSegment(SegmentKey::Recovery, 3.0, 'Z2', PaceBand::Easy, 400, 0.4);
        }
    }

    return $segments;
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

it('falls back to a rep-length window when the laps are just the kilometres, a hit only when it covers the requested work', function (): void {
    $laps = lapRows([[1000, 400], [1000, 300], [1000, 390], [1000, 295], [600, 240]]);

    $fast = SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [judgeRun(4.6, 1625, ['laps' => $laps, 'best_3min_pace' => '4:50'])]);
    $slow = SessionIntentJudge::judge(SessionType::Interval, intervalDay(), JUDGE_PACES, [judgeRun(4.6, 1625, ['laps' => $laps, 'best_3min_pace' => '5:30'])]);
    $oneRepNeeded = SessionIntentJudge::judge(SessionType::Interval, intervalDay(reps: 2), JUDGE_PACES, [judgeRun(4.6, 1625, ['laps' => $laps, 'best_3min_pace' => '4:50'])]);

    expect($fast['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($fast['evidence'])->toMatchArray(['basis' => 'window', 'reps_needed' => 3, 'stimulus_minutes' => 3.0, 'stimulus_source' => 'window'])
        ->and($slow['verdict'])->toBe(IntentVerdict::Missed)
        ->and($oneRepNeeded['verdict'])->toBe(IntentVerdict::Hit)
        ->and($oneRepNeeded['evidence'])->toMatchArray(['basis' => 'window', 'reps_needed' => 1]);
});

/** One 3-minute surge in a 5 x 3 minute day on auto-km laps, the rest at 6:40/km. */
function oneSurgeIntervalRun(array $zones = []): ActivityDetail
{
    $laps = lapRows([[1000, 400], [1000, 400], [1000, 330], [1000, 400], [1000, 400], [1000, 400]]);

    return judgeRun(6.0, 2330, ['laps' => $laps, 'best_3min_pace' => '4:50'] + ($zones === [] ? [] : ['time_in_zone_min' => $zones]));
}

/** One 10-minute block at threshold in a 3 x 10 minute tempo day. */
function oneBlockTempoRun(array $zones = []): ActivityDetail
{
    return judgeRun(9.0, 3300, ['best_10min_pace' => '5:10'] + ($zones === [] ? [] : ['time_in_zone_min' => $zones]));
}

it('cannot read one fast rep of a five-rep day on auto-km laps as hit', function (): void {
    $reading = SessionIntentJudge::judge(SessionType::Interval, intervalDay(reps: 5), JUDGE_PACES, [oneSurgeIntervalRun()]);

    expect($reading['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($reading['evidence'])->toMatchArray(['reps_prescribed' => 5, 'reps_needed' => 4, 'window' => '3min', 'window_pace_sec' => 290, 'stimulus_family' => 'interval', 'stimulus_minutes' => 3.0, 'stimulus_source' => 'window']);
});

it('cannot read one block of a three-block tempo day as hit', function (): void {
    $reading = SessionIntentJudge::judge(SessionType::Tempo, tempoBlocksDay(), JUDGE_PACES, [oneBlockTempoRun()]);

    expect($reading['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($reading['evidence'])->toMatchArray(['block_minutes' => 30.0, 'window' => '10min', 'window_pace_sec' => 310, 'stimulus_family' => 'tempo', 'stimulus_minutes' => 10.0, 'stimulus_source' => 'window']);
});

it('reads a short window at pace as hit when heart rate covers the requested work, and unknown, never missed, when it falls short', function (): void {
    $intervalHit = SessionIntentJudge::judge(SessionType::Interval, intervalDay(reps: 5), JUDGE_PACES, [oneSurgeIntervalRun(['Z1' => 4, 'Z2' => 15, 'Z3' => 4, 'Z4' => 4, 'Z5' => 12])]);
    $intervalShort = SessionIntentJudge::judge(SessionType::Interval, intervalDay(reps: 5), JUDGE_PACES, [oneSurgeIntervalRun(['Z1' => 4, 'Z2' => 21, 'Z3' => 4, 'Z4' => 4, 'Z5' => 6])]);
    $tempoHit = SessionIntentJudge::judge(SessionType::Tempo, tempoBlocksDay(), JUDGE_PACES, [oneBlockTempoRun(['Z1' => 5, 'Z2' => 15, 'Z3' => 5, 'Z4' => 26, 'Z5' => 4])]);
    $tempoShort = SessionIntentJudge::judge(SessionType::Tempo, tempoBlocksDay(), JUDGE_PACES, [oneBlockTempoRun(['Z1' => 5, 'Z2' => 30, 'Z3' => 5, 'Z4' => 15])]);

    expect($intervalHit['verdict'])->toBe(IntentVerdict::Hit)
        ->and($intervalHit['evidence'])->toMatchArray(['basis' => 'heart_rate', 'zone' => 'Z5', 'zone_minutes' => 12.0])
        ->and($intervalShort['verdict'])->toBe(IntentVerdict::Unknown)
        ->and($tempoHit['verdict'])->toBe(IntentVerdict::Hit)
        ->and($tempoHit['evidence'])->toMatchArray(['basis' => 'heart_rate', 'zone' => 'Z4', 'zone_minutes' => 30.0])
        ->and($tempoShort['verdict'])->toBe(IntentVerdict::Unknown);
});

it('holds the dose after a day whose evidence covered one rep or one block', function (): void {
    $resolver = new IntensityPrescriptionResolver();
    $interval = SessionIntentJudge::judge(SessionType::Interval, intervalDay(reps: 5), JUDGE_PACES, [oneSurgeIntervalRun()]);
    $tempo = SessionIntentJudge::judge(SessionType::Tempo, tempoBlocksDay(), JUDGE_PACES, [oneBlockTempoRun()]);

    expect($resolver->resolve(SessionType::Interval, PlanPhase::Peak, null, null, JUDGE_PACES, $interval['verdict'], 12)->hardMinutes)->toBe(12)
        ->and($resolver->resolve(SessionType::Tempo, PlanPhase::Peak, null, null, JUDGE_PACES, $tempo['verdict'], 30)->hardMinutes)->toBe(30);
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

it('lets heart rate rescue a fast easy run that held its cap', function (): void {
    $run = judgeRun(6.0, 1980, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 360]);

    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reading['evidence'])->toMatchArray(['basis' => 'heart_rate', 'hr_cap_bpm' => 150, 'over_cap_minutes' => 6.0, 'over_cap_limit_minutes' => 6.6]);
});

it('lets heart rate confirm a fast easy run was too hard', function (): void {
    $run = judgeRun(6.0, 1980, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 420]);

    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$run]);

    expect($reading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($reading['evidence']['basis'])->toBe('heart_rate');
});

it('judges a capped athlete on heart rate alone, whatever the pace', function (): void {
    $fastUnderCap = judgeRun(6.0, 1980, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 0]);
    $slowOverCap = judgeRun(5.0, 2400, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 600]);

    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$fastUnderCap], heartRateCapped: true)['verdict'])->toBe(IntentVerdict::Hit)
        ->and(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$slowOverCap], heartRateCapped: true)['verdict'])->toBe(IntentVerdict::TooHard)
        ->and(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$slowOverCap])['verdict'])->toBe(IntentVerdict::Hit);
});

it('allows fifteen minutes over the cap on a run of 75 minutes or more, and a fifth of a shorter one', function (int $movingSec, int $overSec, IntentVerdict $verdict): void {
    $run = judgeRun(10.0, $movingSec, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => $overSec]);

    expect(SessionIntentJudge::judge(SessionType::Long, easyDay(), JUDGE_PACES, [$run], heartRateCapped: true)['verdict'])->toBe($verdict);
})->with([
    '90 min, exactly 15 over' => [5400, 900, IntentVerdict::Hit],
    '90 min, 15 min 1 s over' => [5400, 901, IntentVerdict::TooHard],
    '75 min, 15 min over' => [4500, 900, IntentVerdict::Hit],
    '60 min, exactly a fifth over' => [3600, 720, IntentVerdict::Hit],
    '60 min, past a fifth' => [3600, 721, IntentVerdict::TooHard],
]);

it('falls back to the pace-first rule when the runs carry no heart rate', function (): void {
    $fast = judgeRun(6.0, 1980);
    $slow = judgeRun(5.3, 2136);

    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$fast], heartRateCapped: true)['evidence'])->toMatchArray(['basis' => 'pace'])
        ->and(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$fast], heartRateCapped: true)['verdict'])->toBe(IntentVerdict::TooHard)
        ->and(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$slow], heartRateCapped: true)['verdict'])->toBe(IntentVerdict::Hit);
});

it('keeps the marathon-pace block on pace and holds the easy running around it to the cap', function (): void {
    $segments = [
        new SessionSegment(SegmentKey::Easy, 40.0, 'Z2', PaceBand::Easy, 400, 6.0),
        new SessionSegment(SegmentKey::Main, 30.0, 'Z3', PaceBand::Marathon, 340, 5.3),
        new SessionSegment(SegmentKey::Easy, 10.0, 'Z2', PaceBand::Easy, 400, 1.5),
    ];
    $held = judgeRun(12.8, 4800, ['best_30min_pace' => '5:42', 'easy_cap_bpm' => 150, 'over_easy_cap_sec' => 30 * 60 + 600]);
    $raggedAround = judgeRun(12.8, 4800, ['best_30min_pace' => '5:42', 'easy_cap_bpm' => 150, 'over_easy_cap_sec' => 30 * 60 + 960]);

    $heldReading = SessionIntentJudge::judge(SessionType::Long, $segments, JUDGE_PACES, [$held], heartRateCapped: true);
    $raggedReading = SessionIntentJudge::judge(SessionType::Long, $segments, JUDGE_PACES, [$raggedAround], heartRateCapped: true);

    expect($heldReading['verdict'])->toBe(IntentVerdict::Hit)
        ->and($heldReading['evidence'])->toMatchArray(['basis' => 'pace', 'easy_parts' => 'held', 'easy_over_cap_minutes' => 10.0, 'hr_cap_bpm' => 150, 'block_verdict' => 'hit'])
        ->and($raggedReading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($raggedReading['evidence'])->toMatchArray(['easy_parts' => 'too_hard', 'easy_over_cap_minutes' => 16.0, 'block_verdict' => 'hit'])
        ->and(SessionIntentJudge::judge(SessionType::Long, $segments, JUDGE_PACES, [$raggedAround])['verdict'])->toBe(IntentVerdict::Hit);
});

it('never holds a single marathon-pace long segment to the easy cap', function (): void {
    $run = judgeRun(20.0, 6700, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 6000]);

    expect(SessionIntentJudge::judge(SessionType::Long, easyDay(PaceBand::Marathon), JUDGE_PACES, [$run], heartRateCapped: true)['evidence'])
        ->toMatchArray(['basis' => 'pace', 'limit' => 'marathon']);
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
    $run = judgeRun(6.0, 1980, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 120]);

    expect(SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$run])['evidence'])
        ->toMatchArray(['stimulus_family' => 'easy', 'stimulus_source' => 'heart_rate']);
});

it('records self-added hard work on an easy day with the minutes over the cap summed across the day', function (): void {
    $first = judgeRun(6.0, 1980, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 1260]);
    $second = judgeRun(2.0, 700, ['easy_cap_bpm' => 150, 'over_easy_cap_sec' => 420]);
    $noHeartRate = judgeRun(2.0, 700);

    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [$first, $second, $noHeartRate]);

    expect($reading['verdict'])->toBe(IntentVerdict::TooHard)
        ->and($reading['evidence'])->toMatchArray(['stimulus_family' => 'hard', 'stimulus_minutes' => 28.0, 'stimulus_source' => 'heart_rate']);
});

it('records no hard minutes when a too-fast easy run carries no heart rate', function (): void {
    $reading = SessionIntentJudge::judge(SessionType::Easy, easyDay(), JUDGE_PACES, [judgeRun(6.0, 1980)]);

    expect($reading['evidence'])->toMatchArray(['stimulus_family' => 'hard', 'stimulus_source' => 'pace'])
        ->not->toHaveKey('stimulus_minutes');
});

it('judges goal-pace reps on a tempo day as intervals and a goal-pace block on an interval day as a tempo', function (): void {
    $laps = lapRows([[2000, 800], [630, 180], [300, 130], [620, 180], [300, 130], [640, 180], [300, 130], [560, 180], [1000, 400]]);
    $reps = SessionIntentJudge::judge(SessionType::Tempo, intervalDay(), JUDGE_PACES, [judgeRun(6.6, 2300, ['laps' => $laps])]);
    $block = SessionIntentJudge::judge(SessionType::Interval, tempoDay(), JUDGE_PACES, [judgeRun(8.0, 2400, ['best_efforts' => ['20min' => '5:12']])]);

    expect($reps['verdict'])->toBe(IntentVerdict::Hit)
        ->and($reps['evidence'])->toMatchArray(['basis' => 'laps', 'reps_prescribed' => 4])
        ->and($block['evidence'])->toHaveKey('block_minutes');
});

it('judges stepping-stone work against the prescribed stepping-stone pace, not the supported pace', function (): void {
    $context = ['distance_m' => 21_098, 'goal_pace_sec_per_km' => 290, 'kind' => 'half', 'band' => 'unsupported'];
    $segments = SegmentGenerator::forPrescription(SessionType::Tempo, PlanPhase::Peak, 10.0, JUDGE_PACES, new IntensityPrescription(20, PaceBand::Threshold, 290, null, $context));
    $atSteppingStone = SessionIntentJudge::judge(SessionType::Tempo, $segments, JUDGE_PACES, [judgeRun(9.0, 2800, ['best_20min_pace' => '4:52'])]);
    $atSupported = SessionIntentJudge::judge(SessionType::Tempo, $segments, JUDGE_PACES, [judgeRun(9.0, 2800, ['best_20min_pace' => '5:10'])]);

    expect($atSteppingStone['verdict'])->toBe(IntentVerdict::Hit)
        ->and($atSteppingStone['evidence'])->toMatchArray(['target_pace_sec' => 290, 'basis' => 'pace'])
        ->and($atSupported['verdict'])->not->toBe(IntentVerdict::Hit)
        ->and($atSupported['evidence'])->toMatchArray(['target_pace_sec' => 290]);
});
