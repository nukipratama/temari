<?php

declare(strict_types=1);

use App\Support\DataUseStatement;

it('states that the AI service only writes the notes and never trains on the data', function (): void {
    $statement = implode(' ', DataUseStatement::points());

    expect($statement)->toContain('third-party AI service')
        ->and($statement)->toContain('trains any AI model')
        ->and($statement)->not->toMatch('/azure|openai|\bgpt/i');
});

it('states that activity data is never shown to another account', function (): void {
    expect(implode(' ', DataUseStatement::points()))->toContain('no other account can see it');
});

it('points deletion details at the privacy policy instead of restating the retained ledger', function (): void {
    $statement = implode(' ', DataUseStatement::points());

    expect($statement)->toContain('privacy policy')
        ->and($statement)->not->toContain('ledger')
        ->and($statement)->not->toContain('athlete id');
});

it('drops the privacy-policy pointer where the statement is shown on the privacy page itself', function (): void {
    $withPointer = implode(' ', DataUseStatement::points());
    $onPrivacyPage = implode(' ', DataUseStatement::points(pointToPrivacyPolicy: false));

    expect($withPointer)->toContain('The privacy policy spells out the details.')
        ->and($onPrivacyPage)->not->toContain('privacy policy')
        ->and($onPrivacyPage)->toEndWith('your Strava connection is unlinked.');
});

it('keeps the copy free of em-dashes like the rest of the voice', function (): void {
    expect(DataUseStatement::HEADLINE.' '.implode(' ', DataUseStatement::points()))
        ->not->toContain('—');
});

it('names intervals.icu as a recipient only once the athlete connects it', function (): void {
    expect(DataUseStatement::points())->not->toContain(DataUseStatement::INTERVALS_ICU)
        ->and(DataUseStatement::points(intervalsIcuConnected: true))->toContain(DataUseStatement::INTERVALS_ICU)
        ->and(DataUseStatement::points(intervalsIcuConnected: true)[3])->toBe(DataUseStatement::points()[2]);
});
