<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\RunForm;
use App\Services\Run\Story\Card\Styles\BroadsheetRenderer;
use App\Services\Run\Story\Card\Svg;

function broadsheet(): BroadsheetRenderer
{
    return app(BroadsheetRenderer::class);
}

it('sets the masthead with the wordmark, the rarity word and the meta line', function (): void {
    $svg = broadsheet()->render(cardFacts(), CardAspect::Story);

    expect($svg)->toContain('>temari<')
        ->toContain('COMMON')
        ->toContain('SENAYAN · 29°C')
        ->toContain('SUN 13 SEP 2026');
});

it('makes distance the hero on a normal run and finish time on a race or a PR', function (): void {
    $heroOf = function (string $svg): string {
        preg_match('/font-family="Fraunces" font-size="\d{3}(?:\.\d+)?"[^>]*>([^<]+)</', $svg, $found);

        return $found[1] ?? '';
    };

    expect($heroOf(broadsheet()->render(cardFacts(), CardAspect::Story)))->toBe('5.28')
        ->and($heroOf(broadsheet()->render(cardFacts(form: RunForm::Race), CardAspect::Story)))->toBe('32:18')
        ->and($heroOf(broadsheet()->render(cardFacts(form: RunForm::Pr), CardAspect::Story)))->toBe('32:18');
});

it('right-anchors the unit mark at the far margin rather than trailing the figure', function (): void {
    $svg = broadsheet()->render(cardFacts(), CardAspect::Story);

    expect($svg)->toContain('x="996" y="1370" font-family="JetBrains Mono" font-size="62" font-weight="600" text-anchor="end"');
});

it('steps the hero size down as the figure grows, never past the floor', function (): void {
    $sizeOf = function (string $time): float {
        $svg = broadsheet()->render(cardFacts(form: RunForm::Race), CardAspect::Story);
        preg_match('/font-family="Fraunces" font-size="([\d.]+)"[^>]*>'.preg_quote($time, '/').'</', $svg, $found);

        return (float) ($found[1] ?? 0);
    };

    // 268 nominal: "32:18" is five characters, so it takes the 0.8 rung.
    expect($sizeOf('32:18'))->toBe(214.4);
});

it('replaces the masthead with a rarity band on a race, inside the safe zone', function (): void {
    $svg = broadsheet()->render(cardFacts(form: RunForm::Race, rarity: Rarity::Rare), CardAspect::Story);

    expect($svg)->toContain('JAKARTA CITY 10K')
        ->toContain('BIB 0418')
        ->toContain('y="'.CardAspect::SAFE_TOP.'" width="1080" height="104"');
});

it('turns the route into a typographic belt when there is no trace', function (): void {
    $svg = broadsheet()->render(cardFacts(polyline: null), CardAspect::Story);

    expect($svg)->toContain('NO GPS · NO ROUTE')
        ->not->toContain('stroke-width="11"');
});

it('promotes the route and rules the edge down the right on a long run', function (): void {
    $svg = broadsheet()->render(cardFacts(form: RunForm::Long), CardAspect::Story);

    expect($svg)->toContain('stroke-width="16"')
        ->toContain('LONG RUN')
        ->toContain('rotate(90)');
});

it('escalates its chrome one additive layer per rarity tier', function (): void {
    $render = fn (Rarity $rarity): string => broadsheet()->render(cardFacts(rarity: $rarity), CardAspect::Story);
    $edgeBar = 'y="270" width="10"';
    $wedge = 'M0,1920 L0,806.4';

    expect($render(Rarity::Common))->not->toContain($edgeBar)->not->toContain($wedge)
        ->and($render(Rarity::Uncommon))->toContain($edgeBar)->not->toContain($wedge)
        ->and($render(Rarity::Epic))->toContain($edgeBar)->toContain($wedge)
        // Legendary adds the whole-card wash on top of everything below it.
        ->and($render(Rarity::Legendary))->toContain('width="1080" height="1920" fill="#f5a623"');
});

it('warms the ground on a PR and cools it back on anything else', function (): void {
    expect(broadsheet()->render(cardFacts(form: RunForm::Pr), CardAspect::Story))->toContain(Svg::PR_GROUND)
        ->and(broadsheet()->render(cardFacts(), CardAspect::Story))->toContain(Svg::SKY_DEEP);
});

it('boxes a badge chip from the mono advance, or falls back to the serial', function (): void {
    $withBadge = broadsheet()->render(cardFacts(badges: ['Heat Tamer']), CardAspect::Story);

    // "HEAT TAMER" at 22px with 2 tracking: 10 * (13.2 + 2) + 44.
    expect($withBadge)->toContain('width="196"')
        ->toContain('HEAT TAMER')
        ->and(broadsheet()->render(cardFacts(), CardAspect::Story))->toContain('TMR-0418');
});

it('prints a race\'s splits as a right-anchored column', function (): void {
    $svg = broadsheet()->render(
        cardFacts(form: RunForm::Race, splits: [['2K', '11:08'], ['4K', '11:02']]),
        CardAspect::Story,
    );

    expect($svg)->toContain('SPLITS')
        ->toContain('2K  11:08')
        ->toContain('4K  11:02');
});

it('draws the feed card at the square size', function (): void {
    expect(broadsheet()->render(cardFacts(), CardAspect::Feed))
        ->toContain('width="1080" height="1080" viewBox="0 0 1080 1080"');
});
