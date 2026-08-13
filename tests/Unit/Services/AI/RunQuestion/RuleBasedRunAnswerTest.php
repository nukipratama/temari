<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\AI\RunQuestion\RuleBasedRunAnswer;
use App\Services\AI\RunQuestion\RunQuestionTopic;

/** Detached model, so these stay DB-free. */
function answerDetail(array $streamSummary = [], array $attributes = []): ActivityDetail
{
    $detail = new ActivityDetail();
    $detail->forceFill([
        'stream_summary' => $streamSummary === [] ? null : $streamSummary,
        'weather_temp_c' => null,
        'weather_humidity_pct' => null,
        'average_cadence' => null,
        'distance' => 8000.0,
        'moving_time' => 2400,
        ...$attributes,
    ]);

    return $detail;
}

it('answers the drift question with the run own drift figure', function (): void {
    $answer = RuleBasedRunAnswer::for(
        answerDetail(['hr_drift_bpm' => 6.4]),
        RunQuestionTopic::HrDrift->question(),
    );

    expect($answer)->toContain('6.4 bpm');
});

it('reads a high decoupling on a hot day as heat rather than lost fitness', function (): void {
    $answer = RuleBasedRunAnswer::for(
        answerDetail(['decoupling_pct' => 14.2], ['weather_temp_c' => 33]),
        RunQuestionTopic::Decoupling->question(),
    );

    expect($answer)->toContain('14.2%')
        ->and($answer)->toContain('33 degrees')
        ->and($answer)->toContain('shedding heat');
});

it('names the slowest kilometre and its pace', function (): void {
    $answer = RuleBasedRunAnswer::for(
        answerDetail(['per_km' => [
            ['km' => 1, 'pace' => '5:30'],
            ['km' => 2, 'pace' => '6:12'],
            ['km' => 3, 'pace' => '5:44'],
        ]]),
        RunQuestionTopic::SlowestSplit->question(),
    );

    expect($answer)->toContain('km 2')->and($answer)->toContain('6:12/km');
});

it('answers the cadence question with the drop and the average behind it', function (): void {
    $answer = RuleBasedRunAnswer::for(
        answerDetail(['cadence_drop_spm' => 5.0], ['average_cadence' => 86.0]),
        RunQuestionTopic::CadenceDrop->question(),
    );

    expect($answer)->toContain('5 steps a minute')->and($answer)->toContain('172 steps a minute');
});

it('answers the hard-zone question with the combined Z3+ share', function (): void {
    $answer = RuleBasedRunAnswer::for(
        answerDetail(['time_in_zone_pct' => ['Z3' => 22.5, 'Z4' => 8.0]]),
        RunQuestionTopic::HardZones->question(),
    );

    expect($answer)->toContain('30.5%');
});

it('answers the climb question with the grade and the adjusted pace when there is one', function (): void {
    $answer = RuleBasedRunAnswer::for(
        answerDetail(['max_grade_pct' => 9.0, 'gap_pace' => '5:20']),
        RunQuestionTopic::Climb->question(),
    );

    expect($answer)->toContain('9% grade')->and($answer)->toContain('5:20/km');
});

it('falls back to this run headline reading for free text it was never asked', function (): void {
    $answer = RuleBasedRunAnswer::for(answerDetail(), 'should I race a marathon next month?');

    expect($answer)->toContain('8 km')->and($answer)->toContain('5:00/km');
});

it('never invents a number when the run carries none', function (): void {
    $answer = RuleBasedRunAnswer::for(
        answerDetail([], ['distance' => null, 'moving_time' => null]),
        'how did this one go?',
    );

    expect($answer)->not->toContain('km ')->and($answer)->toContain('not much on the clock');
});

it('is deterministic, so re-asking returns the same answer', function (): void {
    $detail = answerDetail(['hr_drift_bpm' => 6.4]);
    $question = RunQuestionTopic::HrDrift->question();

    expect(RuleBasedRunAnswer::for($detail, $question))
        ->toBe(RuleBasedRunAnswer::for($detail, $question));
});
