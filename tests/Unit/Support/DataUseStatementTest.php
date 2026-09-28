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

it('keeps the copy free of em-dashes like the rest of the voice', function (): void {
    expect(DataUseStatement::HEADLINE.' '.implode(' ', DataUseStatement::points()))
        ->not->toContain('—');
});
