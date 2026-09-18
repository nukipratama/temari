<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\RunForm;
use App\Services\Run\Story\Card\Styles\TicketRenderer;
use App\Services\Run\Story\Card\Svg;

function ticket(): TicketRenderer
{
    return app(TicketRenderer::class);
}

it('prints the chassis: an ink band, a perforated tear and a stub', function (): void {
    $svg = ticket()->render(cardFacts(), CardAspect::Story);

    expect($svg)->toContain('filter="url(#ticket-shadow)"')
        ->toContain('fill="'.Rarity::Common->hexColor().'"')   // the band takes the rarity ink
        ->toContain('stroke-dasharray="10 12"')                 // the tear
        ->toContain('>temari<')                                 // the stub's wordmark
        ->toContain('SENAYAN, JAKARTA PUSAT')
        ->toContain('SUN 13 SEP 2026 · 05:41 · 29°C');
});

it('sets the whole body in mono, with Fraunces only in the wordmark', function (): void {
    $svg = ticket()->render(cardFacts(), CardAspect::Story);

    expect(substr_count($svg, 'font-family="Fraunces"'))->toBe(1)
        ->and($svg)->not->toContain('font-family="Plus Jakarta Sans"');
});

it('picks the band ink by luminance so both ends of the ladder stay legible', function (): void {
    expect(ticket()->render(cardFacts(rarity: Rarity::Legendary), CardAspect::Story))
        ->toContain('fill="'.Svg::INK.'">EASY RUN')
        ->and(ticket()->render(cardFacts(rarity: Rarity::Rare), CardAspect::Story))
        ->toContain('fill="'.Svg::CREAM.'">EASY RUN');
});

it('turns the chassis into a bib on a race', function (): void {
    $svg = ticket()->render(cardFacts(form: RunForm::Race, rarity: Rarity::Rare), CardAspect::Story);

    expect($svg)->toContain('JAKARTA CITY 10K')
        ->toContain('font-size="340" font-weight="800" text-anchor="middle" fill="#16181b">0418')
        ->toContain('>10K<')
        ->toContain('FINISH');
});

it('gives a race\'s feed card its chip splits instead of a route window', function (): void {
    $svg = ticket()->render(
        cardFacts(form: RunForm::Race, splits: [['2K', '11:08'], ['4K', '11:02']]),
        CardAspect::Feed,
    );

    expect($svg)->toContain('CHIP SPLITS')
        ->toContain('11:08')
        ->not->toContain('>ROUTE<');
});

it('cancels the route window when the run has no trace', function (): void {
    $svg = ticket()->render(cardFacts(polyline: null), CardAspect::Story);

    expect($svg)->toContain('NO SIGNAL · NO ROUTE')
        ->toContain(Svg::EMBER)
        ->not->toContain('>ROUTE<');
});

it('stamps a PR across the paper and chips the hero box', function (): void {
    $svg = ticket()->render(cardFacts(form: RunForm::Pr, rarity: Rarity::Epic), CardAspect::Story);

    expect($svg)->toContain('PERSONAL RECORD')
        ->toContain('rotate(-8)')
        ->toContain('NEW BEST')
        ->toContain(Svg::CITRUS);
});

it('escalates through print finishing, tier by tier', function (): void {
    $render = fn (Rarity $rarity): string => ticket()->render(cardFacts(rarity: $rarity), CardAspect::Story);
    $innerBorder = 'x="72" y="286" width="936"';

    expect($render(Rarity::Uncommon))->not->toContain($innerBorder)->not->toContain('url(#ticket-foil)')
        ->and($render(Rarity::Rare))->toContain($innerBorder)->not->toContain('url(#ticket-foil)')
        ->and($render(Rarity::Epic))->toContain('url(#ticket-foil)')
        // Legendary adds a second perforation row on top of the first.
        ->and(substr_count($render(Rarity::Legendary), 'stroke-dasharray="10 12"'))->toBe(2);
});

it('counts the tier in the stub\'s rarity dots', function (): void {
    $dots = fn (Rarity $rarity): int => substr_count(
        ticket()->render(cardFacts(rarity: $rarity), CardAspect::Story),
        'r="8" fill="'.$rarity->hexColor().'"',
    );

    expect($dots(Rarity::Common))->toBe(1)
        ->and($dots(Rarity::Epic))->toBe(4);
});

it('shrinks the distance figure only as far as the box needs', function (): void {
    $svg = ticket()->render(cardFacts(), CardAspect::Story);

    // "5.28" at 224 is 537px inside a 730px box, so the nominal size stands.
    expect($svg)->toContain('font-size="224" font-weight="800"');
});

it('sits the ticket inside the story safe zone and fills the square feed card', function (): void {
    expect(ticket()->render(cardFacts(), CardAspect::Story))
        ->toContain('y="272" width="964" height="1368"')
        ->and(ticket()->render(cardFacts(), CardAspect::Feed))
        ->toContain('width="1080" height="1080" viewBox="0 0 1080 1080"');
});

it('reflows the race feed to the route window when there are no chip splits', function (): void {
    $svg = ticket()->render(cardFacts(form: RunForm::Race), CardAspect::Feed);

    expect($svg)->not->toContain('CHIP SPLITS')
        ->toContain('>ROUTE<');
});

it('drops a stat cell it cannot fill rather than ruling an empty one', function (): void {
    // No heart rate, no elevation and no start clock leaves the flex cell with
    // nothing: the row rules two cells, not a labelled void.
    $svg = ticket()->render(
        cardFacts(heartRate: null, elevation: null, clock: ''),
        CardAspect::Story,
    );

    expect($svg)->toContain('>TIME<')
        ->toContain('>PACE<')
        ->not->toContain('>START<')
        ->not->toContain('>AVG HR<');
});

it('boxes a badge chip from the mono advance', function (): void {
    // "HEAT TAMER" at 22px with 2 tracking: 10 * (13.2 + 2) + 46.
    expect(ticket()->render(cardFacts(badges: ['Heat Tamer']), CardAspect::Story))
        ->toContain('width="198" height="58"')
        ->toContain('HEAT TAMER');
});
