<?php

declare(strict_types=1);

use App\Support\TrainingDisclaimer;

it('says plainly that the numbers are not medical advice', function (): void {
    expect(TrainingDisclaimer::TEXT)->toContain('not medical advice')
        ->and(TrainingDisclaimer::TEXT)->toContain('doctor');
});

it('names what the plan engine cannot see', function (): void {
    $scope = implode(' ', TrainingDisclaimer::scope());

    expect($scope)->toContain('injury')
        ->and($scope)->toContain('illness')
        ->and($scope)->toContain('never reached Strava');
});

it('keeps the copy free of em-dashes like the rest of the voice', function (): void {
    $copy = TrainingDisclaimer::HEADLINE.' '.TrainingDisclaimer::TEXT.' '.implode(' ', TrainingDisclaimer::scope());

    expect($copy)->not->toContain('—');
});
