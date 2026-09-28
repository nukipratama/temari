<?php

declare(strict_types=1);

use App\Support\TrainingDisclaimer;

it('says plainly that the numbers are not medical advice', function (): void {
    expect(TrainingDisclaimer::TEXT)->toContain('not medical advice')
        ->and(TrainingDisclaimer::TEXT)->toContain('doctor');
});

it('keeps a short friend-voice line for the surfaces that link to the full text', function (): void {
    expect(TrainingDisclaimer::SHORT)->toContain('not a check-up')
        ->and(TrainingDisclaimer::SHORT)->toContain('see a pro')
        ->and(mb_strlen(TrainingDisclaimer::SHORT))->toBeLessThan(mb_strlen(TrainingDisclaimer::TEXT));
});

it('names what the plan engine cannot see', function (): void {
    $scope = implode(' ', TrainingDisclaimer::scope());

    expect($scope)->toContain('injury')
        ->and($scope)->toContain('illness')
        ->and($scope)->toContain('never reached Strava');
});

it('keeps the copy free of em-dashes like the rest of the voice', function (): void {
    $copy = TrainingDisclaimer::HEADLINE.' '.TrainingDisclaimer::SHORT.' '.TrainingDisclaimer::TEXT.' '.implode(' ', TrainingDisclaimer::scope());

    expect($copy)->not->toContain('—');
});
