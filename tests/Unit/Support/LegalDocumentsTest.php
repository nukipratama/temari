<?php

declare(strict_types=1);

use App\Support\DataUseStatement;
use App\Support\LegalDocuments;
use App\Support\TrainingDisclaimer;

/**
 * @return list<array{slug: string, title: string, updated: string, intro: string, sections: list<array{heading: string, paragraphs: list<string>}>}>
 */
function allLegalDocuments(): array
{
    return [
        LegalDocuments::terms(),
        LegalDocuments::privacy(),
        LegalDocuments::trainingDisclaimer(),
    ];
}

function legalProse(): string
{
    return implode(' ', array_map(
        fn (array $document): string => $document['title'].' '.$document['intro'].' '.implode(' ', array_merge(
            ...array_map(fn (array $section): array => [$section['heading'], ...$section['paragraphs']], $document['sections']),
        )),
        allLegalDocuments(),
    ));
}

it('gives every document a slug, a title, a date and at least one section', function (): void {
    foreach (allLegalDocuments() as $document) {
        expect($document['slug'])->not->toBe('')
            ->and($document['title'])->not->toBe('')
            ->and($document['updated'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and($document['sections'])->not->toBeEmpty();

        foreach ($document['sections'] as $section) {
            expect($section['heading'])->not->toBe('')
                ->and($section['paragraphs'])->not->toBeEmpty();
        }
    }
});

it('gives the three documents distinct slugs', function (): void {
    $slugs = array_map(fn (array $document): string => $document['slug'], allLegalDocuments());

    expect($slugs)->toBe(['terms', 'privacy', 'training-disclaimer']);
});

it('serves the data-use wording from DataUseStatement rather than a second copy', function (): void {
    $privacyData = collect(LegalDocuments::privacy()['sections'])->firstOrFail(
        fn (array $section): bool => $section['heading'] === DataUseStatement::HEADLINE,
    );

    expect($privacyData['paragraphs'])->toBe(DataUseStatement::points());
});

it('folds how the notes get written into privacy, under the anchor the old page redirects to', function (): void {
    $notes = collect(LegalDocuments::privacy()['sections'])->firstOrFail(
        fn (array $section): bool => ($section['id'] ?? null) === LegalDocuments::NOTES_SECTION_ID,
    );

    expect(implode(' ', $notes['paragraphs']))->toContain('does not mark which is which')
        ->and(implode(' ', $notes['paragraphs']))->toContain('not checked before you read them');
});

it('serves the training disclaimer from TrainingDisclaimer rather than a second wording', function (): void {
    expect(LegalDocuments::trainingDisclaimer()['intro'])->toBe(TrainingDisclaimer::TEXT);

    $terms = collect(LegalDocuments::terms()['sections'])->firstOrFail(
        fn (array $section): bool => $section['heading'] === TrainingDisclaimer::HEADLINE,
    );
    expect($terms['paragraphs'])->toContain(TrainingDisclaimer::TEXT);
});

it('discloses the one thing account deletion keeps, so the promise stays true', function (): void {
    $deletion = collect(LegalDocuments::privacy()['sections'])->firstOrFail(
        fn (array $section): bool => $section['heading'] === 'Deleting your account',
    );

    expect(implode(' ', $deletion['paragraphs']))->toContain('what your notes cost to write')
        ->and(implode(' ', $deletion['paragraphs']))->toContain('Strava athlete id');
});

it('states the cost-ledger retention in exactly one line, on privacy only', function (): void {
    $lines = collect(allLegalDocuments())
        ->flatMap(fn (array $document): array => array_merge(...array_column($document['sections'], 'paragraphs')))
        ->merge(DataUseStatement::points())
        ->filter(fn (string $line): bool => str_contains($line, 'Strava athlete id') && str_contains($line, 'cost'));

    expect($lines)->toHaveCount(1);
});

it('never names the AI vendor or model in the legal copy', function (): void {
    expect(legalProse())->not->toMatch('/azure|openai|\bgpt|language model/i');
});

it('does not claim a per-account AI switch the app has no toggle for', function (): void {
    $prose = legalProse();

    expect($prose)->toContain('no per-account switch for AI text');
});

it('keeps the legal copy free of em-dashes like the rest of the voice', function (): void {
    expect(legalProse())->not->toContain('—');
});
