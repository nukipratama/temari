<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\SegmentKey;
use App\Enums\SessionType;
use App\Services\Run\Plan\SessionPurpose;
use App\Services\Run\Plan\SessionSegment;

/**
 * @param  list<SessionSegment>  $segments
 * @param  array{pace_kind: string, pace_source: string}|null  $fastFinish
 * @param  array{pace_kind: string, pace_source: string}|null  $raceLong
 * @param  array{distance_m: int, aim_time_sec: int}|null  $timeTrial
 */
function purposeOf(SessionType $type, array $segments = [], ?string $goalPace = null, bool $steppingStone = false, bool $closesAtGoalPace = false, ?array $fastFinish = null, ?array $raceLong = null, ?array $timeTrial = null): ?string
{
    return SessionPurpose::of($type, $segments, $goalPace, $steppingStone, $closesAtGoalPace, $fastFinish, $raceLong, $timeTrial);
}

function purposeEasyBlock(): SessionSegment
{
    return new SessionSegment(SegmentKey::Main, 40.0, 'Z2', PaceBand::Easy, 400, 6.0);
}

function purposeWorkBlock(): SessionSegment
{
    return new SessionSegment(SegmentKey::Main, 20.0, 'Z4', PaceBand::Threshold, 300, 4.0);
}

it('says what each session type is for', function (SessionType $type, array $segments, string $purpose): void {
    expect(purposeOf($type, $segments))->toBe($purpose);
})->with([
    'easy' => [SessionType::Easy, [purposeEasyBlock()], "easy means easy. slow enough to talk, that's the whole point."],
    'long' => [SessionType::Long, [purposeEasyBlock()], 'time on feet. builds the engine the race runs on. chatty effort the whole way.'],
    'long with work' => [SessionType::Long, [purposeEasyBlock(), purposeWorkBlock()], 'long run with goal-pace work. rehearses race day on tired legs.'],
    'tempo' => [SessionType::Tempo, [purposeWorkBlock()], 'comfortably hard. teaches you to hold a pace without tipping over.'],
    'interval' => [SessionType::Interval, [purposeWorkBlock()], 'short, hard reps. lifts your ceiling so race pace feels roomier.'],
    'rest' => [SessionType::Rest, [], 'rest day. this is where the training actually lands.'],
    'race' => [SessionType::Race, [purposeWorkBlock()], 'race day. trust the work, and start slower than you want to.'],
]);

it('only calls a quality day hard when it has work above Z2', function (SessionType $type): void {
    expect(purposeOf($type, [purposeEasyBlock()]))->toBeNull();
})->with([SessionType::Tempo, SessionType::Interval]);

it('says the time to aim around and what a time trial is for', function (): void {
    expect(purposeOf(SessionType::Interval, goalPace: '5k', timeTrial: ['distance_m' => 5_000, 'aim_time_sec' => 1_410]))
        ->toBe('aim around 23:30. checks your fitness so your paces stay honest.');
});

it('says which goal or stepping-stone pace the session rehearses', function (string $kind, bool $steppingStone, bool $closes, string $purpose): void {
    expect(purposeOf(SessionType::Tempo, [purposeWorkBlock()], $kind, $steppingStone, $closes))->toBe($purpose);
})->with([
    ['5k', false, false, 'rehearsing your 5K goal pace.'],
    ['10k', false, false, 'rehearsing your 10K goal pace.'],
    ['half', false, false, 'rehearsing your half marathon goal pace.'],
    ['marathon', false, false, 'rehearsing your marathon goal pace.'],
    ['10k', true, false, 'rehearsing your 10K stepping-stone pace.'],
    ['10k', true, true, 'rehearsing your 10K stepping-stone pace, closing at goal pace.'],
]);

it('says where a fast finish runs, never goal pace unless it is', function (string $kind, string $source, string $pace): void {
    expect(purposeOf(SessionType::Long, [purposeEasyBlock(), purposeWorkBlock()], fastFinish: ['pace_kind' => $kind, 'pace_source' => $source]))
        ->toBe("the last fifth at your {$pace}. rehearses race pace on tired legs.");
})->with([
    ['half', 'goal', 'half marathon goal pace'],
    ['10k', 'stepping_stone', '10K stepping-stone pace'],
    ['10k', 'supported', 'supported 10K pace'],
]);

it('says where a marathon race long\'s block runs, never goal pace unless it is', function (string $source, string $pace): void {
    expect(purposeOf(SessionType::Long, [purposeEasyBlock(), purposeWorkBlock()], raceLong: ['pace_kind' => 'marathon', 'pace_source' => $source]))
        ->toBe("long run with a block at your {$pace}. rehearses race day on tired legs.");
})->with([
    ['goal', 'marathon goal pace'],
    ['stepping_stone', 'marathon stepping-stone pace'],
    ['supported', 'supported marathon pace'],
]);
