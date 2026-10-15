<?php

declare(strict_types=1);

use App\Enums\IntentVerdict;
use App\Services\AI\Narrators\SpeechFactCheck;

/** @return array<string, mixed> */
function speechFacts(): array
{
    return [
        'get_run_summary' => ['distance_km' => 7.33, 'pace_sec_per_km' => 386.9, 'pace_formatted' => '6:27', 'elapsed_time_formatted' => '48:20'],
        'get_hr_share' => ['z3_pct' => 60.2],
        'get_planned_sessions' => ['days' => [[
            'distance_km' => 7.3,
            'target_pace_formatted' => '6:03',
            'intent' => 'the hard block ran well past the effort it asked for',
            'intent_detail' => 'best 20-minute stretch averaged 5:35/km, well quicker than the 6:03/km target pace',
        ]]],
    ];
}

it('accepts a speech whose paces, distances and shares all come from the tools, at the precision quoted', function (): void {
    $speech = '7.3 km all told, the block at 5:35/km against a 6:03 target, 60% of it in Z3, done in 48:20.';

    expect(SpeechFactCheck::complaint($speech, speechFacts(), IntentVerdict::TooHard))->toBeNull();
});

it('rejects a distance, pace or share no tool returned', function (string $speech, string $figure): void {
    expect(SpeechFactCheck::complaint($speech, speechFacts(), null))
        ->toContain($figure)
        ->toContain('no tool returned');
})->with([
    'derived distance' => ['you went 0.9 km past the plan.', '0.9'],
    'made-up pace' => ['you held 5:50/km through the block.', '5:50'],
    'made-up share' => ['72% of it sat in Z3.', '72'],
]);

it('keeps the sourced figures when one tool value cannot be encoded', function (mixed $unencodable): void {
    $facts = [...speechFacts(), 'get_weather' => ['summary' => $unencodable]];

    expect(SpeechFactCheck::complaint('7.3 km at 6:27, 60% in Z3.', $facts, null))->toBeNull();
})->with([
    'invalid UTF-8' => ["\xB1\x31"],
    'not a number' => [NAN],
]);

it('leaves figures other than paces, distances and shares to the prompt', function (): void {
    expect(SpeechFactCheck::complaint('25°C, 12 straight weeks, 3 runs this week.', speechFacts(), null))->toBeNull();
});

it('rejects a speech that turns the verdict around', function (IntentVerdict $verdict, string $speech): void {
    expect(SpeechFactCheck::complaint($speech, speechFacts(), $verdict))
        ->toContain('get_planned_sessions');
})->with([
    'too hard read as easy' => [IntentVerdict::TooHard, 'this one came in easier than written.'],
    'missed read as too hard' => [IntentVerdict::Missed, 'it ran harder than the plan asked.'],
    'hit read as too hard' => [IntentVerdict::Hit, 'you went harder than planned.'],
    'hit read as too easy' => [IntentVerdict::Hit, 'it came in softer than asked.'],
]);

it('accepts a speech that tells the verdict the same way round', function (?IntentVerdict $verdict, string $speech): void {
    expect(SpeechFactCheck::complaint($speech, speechFacts(), $verdict))->toBeNull();
})->with([
    'too hard' => [IntentVerdict::TooHard, 'the block ran harder than the day asked.'],
    'missed' => [IntentVerdict::Missed, 'it came in easier than written.'],
    'unjudged day' => [IntentVerdict::Unknown, 'it came in easier than written.'],
    'no plan' => [null, 'harder than planned, maybe.'],
    'not about the plan' => [IntentVerdict::Hit, 'the heat made it harder than it looked.'],
]);
