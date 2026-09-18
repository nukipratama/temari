<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\RunForm;
use App\Services\Run\Story\Card\Styles\TopoPlateRenderer;
use App\Services\Run\Story\Card\Svg;

function topoPlate(): TopoPlateRenderer
{
    return app(TopoPlateRenderer::class);
}

it('frames the plate with a ticked collar, a scale bar and a north arrow', function (): void {
    $svg = topoPlate()->render(cardFacts(), CardAspect::Story);

    expect($svg)->toContain('PLATE 0418 · SENAYAN')
        ->toContain('>N<')            // the north arrow's letter
        ->toContain('>2 KM<')          // the scale bar's far label
        ->toContain('>temari<');
});

it('rules the title block with distance, time and pace, then the metadata row', function (): void {
    $svg = topoPlate()->render(cardFacts(), CardAspect::Story);

    expect($svg)->toContain('>5.28 KM<')
        ->toContain('>32:18<')
        ->toContain('>6:07/K<')
        ->toContain('>13.09.26<')
        ->toContain('>05:41<')
        ->toContain('>142<')
        ->toContain('>18 M<');
});

it('drops a toggled-off cell from the metadata row rather than printing a gap', function (): void {
    $svg = topoPlate()->render(cardFacts(heartRate: null, elevation: null), CardAspect::Story);

    expect($svg)->not->toContain('AVG HR')
        ->not->toContain('>ELEV<');
});

it('relabels time and gates the finish on a race', function (): void {
    $svg = topoPlate()->render(
        cardFacts(form: RunForm::Race, rarity: Rarity::Rare, splits: [['2K', '11:08']]),
        CardAspect::Story,
    );

    expect($svg)->toContain('>FINISH<')
        ->toContain('>SPLITS<')
        ->toContain('>11:08<')
        ->not->toContain('>TIME<');
});

it('surveys nothing when there is no trace', function (): void {
    $svg = topoPlate()->render(cardFacts(polyline: null), CardAspect::Story);

    expect($svg)->toContain('UNSURVEYED')
        ->toContain('NO TRACE · 5.28 KM')
        // No contour rings and no cream-cased trace: nothing was surveyed.
        ->not->toContain('stroke-width="22"')
        ->not->toContain(Svg::LEAF_INK);
});

it('numbers the kilometres along a long run and coarsens its scale bar', function (): void {
    $svg = topoPlate()->render(cardFacts(form: RunForm::Long), CardAspect::Story);

    expect($svg)->toContain('>4 KM<')
        ->toContain('stroke-width="4"');   // the km tick nodes
});

it('records the run\'s own shape rather than an invented terrain profile', function (): void {
    $svg = topoPlate()->render(
        cardFacts(form: RunForm::Long, paceProfile: [0.0, 1.0, 0.5]),
        CardAspect::Story,
    );

    expect($svg)->toContain('PACE PROFILE')
        ->and(topoPlate()->render(cardFacts(form: RunForm::Long), CardAspect::Story))
        ->not->toContain('PACE PROFILE');
});

it('escalates the survey density with the rarity tier', function (): void {
    $rings = fn (Rarity $rarity): int => substr_count(
        topoPlate()->render(cardFacts(rarity: $rarity), CardAspect::Story),
        'stroke="'.Svg::LEAF_INK.'"',
    );

    expect($rings(Rarity::Common))->toBe(7)
        ->and($rings(Rarity::Legendary))->toBe(15);
});

it('tints under the trace and promotes it to the rarity colour from rare up', function (): void {
    expect(topoPlate()->render(cardFacts(rarity: Rarity::Uncommon), CardAspect::Story))
        ->toContain('stroke="'.Svg::INK.'" stroke-width="10"')
        ->and(topoPlate()->render(cardFacts(rarity: Rarity::Rare), CardAspect::Story))
        ->toContain('stroke="'.Rarity::Rare->hexColor().'" stroke-width="10"')
        ->toContain('fill="'.Rarity::Rare->hexColor().'" fill-opacity="0.07"');
});

it('stamps the survey and centre-lines the trace from epic up', function (): void {
    $svg = topoPlate()->render(cardFacts(rarity: Rarity::Epic), CardAspect::Story);

    expect($svg)->toContain('CERTIFIED')
        ->toContain('stroke-dasharray="2 22"')
        ->and(topoPlate()->render(cardFacts(form: RunForm::Pr, rarity: Rarity::Epic), CardAspect::Story))
        ->toContain('NEW BEST')
        ->toContain(Svg::CITRUS);
});

it('keeps the legend from colliding: whole badges only, never half a name', function (): void {
    $svg = topoPlate()->render(cardFacts(badges: ['Speedster', 'Negative Split']), CardAspect::Story);

    expect($svg)->toContain('>SPEEDSTER<')
        ->not->toContain('NEGATIVE SPL<');
});

it('sits the plate inside the story safe zone and fills the square feed card', function (): void {
    expect(topoPlate()->render(cardFacts(), CardAspect::Story))
        ->toContain('y="270" width="972" height="1378"')
        ->and(topoPlate()->render(cardFacts(), CardAspect::Feed))
        ->toContain('width="1080" height="1080" viewBox="0 0 1080 1080"');
});
