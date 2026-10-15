<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Plan\DayRanLabel;
use App\Services\Run\Plan\SessionSegment;

/** @return list<array{km: float, seconds: int|null, avg_hr: int|null}> */
function ranRuns(?int $heartRate = 146): array
{
    return [['km' => 7.3, 'seconds' => 3000, 'avg_hr' => $heartRate]];
}

it('answers a tempo in its blocks, not the whole-run average', function (): void {
    $evidence = ['block_minutes' => 20, 'blocks_prescribed' => 2, 'block_pace_sec' => 339, 'target_pace_sec' => 363, 'basis' => 'pace'];

    expect(DayRanLabel::of(SessionType::Tempo, false, $evidence, 387, [], ranRuns()))->toBe('2 × 10 min at 5:39/km')
        ->and(DayRanLabel::of(SessionType::Tempo, false, ['blocks_prescribed' => 1] + $evidence, 387, [], ranRuns()))->toBe('20 min at 5:39/km');
});

it('answers intervals with the reps on pace and the average of every rep', function (): void {
    $evidence = ['reps_prescribed' => 5, 'reps_at_pace' => 4, 'rep_pace_sec' => 286, 'basis' => 'laps'];

    expect(DayRanLabel::of(SessionType::Interval, false, $evidence, 400, [], ranRuns()))->toBe('4 of 5 reps at pace, avg 4:46/km')
        ->and(DayRanLabel::of(SessionType::Interval, false, ['reps_prescribed' => 5, 'reps_at_pace' => 2], 400, [], ranRuns()))->toBe('2 of 5 reps at pace');
});

it('names the window it measured when no block was found', function (): void {
    $evidence = ['block_minutes' => 20, 'window' => '10min', 'window_pace_sec' => 331, 'basis' => 'pace'];

    expect(DayRanLabel::of(SessionType::Tempo, false, $evidence, 387, [], ranRuns()))->toBe('best 10 min at 5:31/km');
});

it('answers a quality day heart rate decided in minutes in the zone', function (): void {
    $evidence = ['block_minutes' => 20, 'block_pace_sec' => 380, 'basis' => 'heart_rate', 'zone' => 'Z4', 'zone_minutes' => 17.6];

    expect(DayRanLabel::of(SessionType::Tempo, false, $evidence, 400, [], ranRuns()))->toBe('18 min in Z4+');
});

it('answers an easy or long day with heart rate beside the pace', function (): void {
    $evidence = ['pace_sec' => 412, 'ceiling_pace_sec' => 340, 'basis' => 'pace'];
    $twoRuns = [['km' => 4.0, 'seconds' => 1500, 'avg_hr' => 140], ['km' => 4.0, 'seconds' => 500, 'avg_hr' => 160]];

    expect(DayRanLabel::of(SessionType::Easy, false, $evidence, 412, [], ranRuns()))->toBe('avg 146 bpm · 6:52/km')
        ->and(DayRanLabel::of(SessionType::Long, false, $evidence, 412, [], $twoRuns))->toBe('avg 145 bpm · 6:52/km')
        ->and(DayRanLabel::of(SessionType::Easy, false, $evidence, 412, [], ranRuns(null)))->toBe('6:52/km');
});

it('answers a long run with a block in the block and the easy part against the cap', function (): void {
    $segments = [
        new SessionSegment(SegmentKey::Main, 70.0, 'Z2', PaceBand::Easy, 420, 13.0),
        new SessionSegment(SegmentKey::Main, 16.0, 'Z3', PaceBand::Marathon, 320, 3.0),
    ];
    $evidence = ['block_minutes' => 16, 'block_pace_sec' => 316, 'basis' => 'pace'];

    expect(DayRanLabel::of(SessionType::Long, false, $evidence + ['easy_over_cap_minutes' => 0.4], 400, $segments, ranRuns()))->toBe('3 km at 5:16/km · easy part under cap')
        ->and(DayRanLabel::of(SessionType::Long, false, $evidence + ['easy_over_cap_minutes' => 4.2], 400, $segments, ranRuns()))->toBe('3 km at 5:16/km · easy part 4 min over cap')
        ->and(DayRanLabel::of(SessionType::Long, false, $evidence, 400, $segments, ranRuns()))->toBe('3 km at 5:16/km');
});

it('keeps the whole-run pace on a time trial or race, and on a day the judge could not read', function (): void {
    expect(DayRanLabel::of(SessionType::Tempo, true, ['block_minutes' => 20, 'block_pace_sec' => 300], 305, [], ranRuns()))->toBe('5:05/km')
        ->and(DayRanLabel::of(SessionType::Race, false, [], 305, [], ranRuns()))->toBe('5:05/km')
        ->and(DayRanLabel::of(SessionType::Tempo, false, ['advice_history' => 'unknown'], 305, [], ranRuns()))->toBe('5:05/km')
        ->and(DayRanLabel::of(SessionType::Tempo, false, [], null, [], []))->toBeNull();
});
