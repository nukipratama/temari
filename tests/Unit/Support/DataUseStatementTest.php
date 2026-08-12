<?php

declare(strict_types=1);

use App\Support\DataUseStatement;

it('states that AI use is inference and never training', function (): void {
    $statement = implode(' ', DataUseStatement::points());

    expect($statement)->toContain('Azure OpenAI')
        ->and($statement)->toContain('inference')
        ->and($statement)->toContain('trains or fine-tunes');
});

it('states that activity data is never shown to another account', function (): void {
    expect(implode(' ', DataUseStatement::points()))->toContain('no other account can see it');
});

it('keeps the copy free of em-dashes like the rest of the voice', function (): void {
    expect(DataUseStatement::HEADLINE.' '.implode(' ', DataUseStatement::points()))
        ->not->toContain('—');
});
