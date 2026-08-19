<?php

declare(strict_types=1);

use App\Models\ActivityDetail;
use App\Services\AI\RunQuestion\RunQuestionSeeds;
use App\Services\AI\RunQuestion\RunQuestionTopic;

/** Detached model, so these stay DB-free. */
function runSeedDetail(array $streamSummary = [], array $attributes = []): ActivityDetail
{
    $detail = new ActivityDetail();
    $detail->forceFill([
        'stream_summary' => $streamSummary === [] ? null : $streamSummary,
        'weather_temp_c' => null,
        'distance' => 8000.0,
        'moving_time' => 2400,
        ...$attributes,
    ]);

    return $detail;
}

it('always offers the baseline comparison, since a run alone is enough for it', function (): void {
    expect(RunQuestionSeeds::for(runSeedDetail()))->toBe([RunQuestionTopic::Baseline]);
});

it('offers only the baseline on a summary-state run with no streams', function (): void {
    expect(RunQuestionSeeds::for(runSeedDetail([], ['weather_temp_c' => 24])))
        ->toBe([RunQuestionTopic::Baseline]);
});

it('offers the drift question only once the drift clears the noise floor', function (): void {
    expect(RunQuestionSeeds::for(runSeedDetail(['hr_drift_bpm' => 2.0])))
        ->not->toContain(RunQuestionTopic::HrDrift);

    expect(RunQuestionSeeds::for(runSeedDetail(['hr_drift_bpm' => 6.4])))
        ->toContain(RunQuestionTopic::HrDrift);
});

it('offers decoupling whenever the run carries the reading at all, zero included', function (): void {
    expect(RunQuestionSeeds::for(runSeedDetail(['decoupling_pct' => 0.0])))
        ->toContain(RunQuestionTopic::Decoupling);
});

it('offers the negative-split question only when the run actually had one', function (): void {
    expect(RunQuestionSeeds::for(runSeedDetail(['negative_split' => false])))
        ->not->toContain(RunQuestionTopic::NegativeSplit);

    expect(RunQuestionSeeds::for(runSeedDetail(['negative_split' => true])))
        ->toContain(RunQuestionTopic::NegativeSplit);
});

it('offers the slowest-km question only once there are splits to compare', function (): void {
    $twoKm = ['per_km' => [['km' => 1, 'pace' => '5:30'], ['km' => 2, 'pace' => '5:40']]];
    expect(RunQuestionSeeds::for(runSeedDetail($twoKm)))->not->toContain(RunQuestionTopic::SlowestSplit);

    $threeKm = ['per_km' => [...$twoKm['per_km'], ['km' => 3, 'pace' => '5:50']]];
    expect(RunQuestionSeeds::for(runSeedDetail($threeKm)))->toContain(RunQuestionTopic::SlowestSplit);
});

it('offers the hard-zone question off the combined Z3+ share', function (): void {
    $summary = ['time_in_zone_pct' => ['Z2' => 70.0, 'Z3' => 18.0, 'Z4' => 9.0]];

    expect(RunQuestionSeeds::for(runSeedDetail($summary)))->toContain(RunQuestionTopic::HardZones);
});

it('offers the heat question only on a hot run', function (): void {
    expect(RunQuestionSeeds::for(runSeedDetail([], ['weather_temp_c' => 26])))
        ->not->toContain(RunQuestionTopic::Heat);

    expect(RunQuestionSeeds::for(runSeedDetail([], ['weather_temp_c' => 33])))
        ->toContain(RunQuestionTopic::Heat);
});

it('caps the suggestions so they stay suggestions', function (): void {
    $everything = runSeedDetail([
        'hr_drift_bpm' => 8.0,
        'decoupling_pct' => 12.0,
        'negative_split' => true,
        'cadence_drop_spm' => 5.0,
        'per_km' => [['km' => 1, 'pace' => '5:30'], ['km' => 2, 'pace' => '5:40'], ['km' => 3, 'pace' => '5:50']],
        'time_in_zone_pct' => ['Z3' => 40.0],
        'max_grade_pct' => 9.0,
    ], ['weather_temp_c' => 33]);

    expect(RunQuestionSeeds::for($everything))->toHaveCount(4);
});

it('matches a question back to the suggestion it came from, punctuation and case aside', function (): void {
    $detail = runSeedDetail(['hr_drift_bpm' => 6.4]);

    expect(RunQuestionSeeds::match('Why did my heart rate drift up', $detail))
        ->toBe(RunQuestionTopic::HrDrift);
});

it('matches nothing for free text the run never suggested', function (): void {
    expect(RunQuestionSeeds::match('should I race a marathon?', runSeedDetail()))->toBeNull();
});

it('will not match a suggestion this run never earned', function (): void {
    expect(RunQuestionSeeds::match(RunQuestionTopic::Heat->question(), runSeedDetail()))->toBeNull();
});

it('gives every topic a question written the way a user types', function (): void {
    foreach (RunQuestionTopic::cases() as $topic) {
        expect($topic->question())->toEndWith('?')->and($topic->question())->not->toBe('');
    }
});
