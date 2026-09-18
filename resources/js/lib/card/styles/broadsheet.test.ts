import { describe, expect, it } from 'vitest';

import { printFacts } from '@/lib/card/facts';
import { PR_GROUND, SKY_DEEP } from '@/lib/card/palette';
import { renderBroadsheet } from '@/lib/card/styles/broadsheet';
import {
    ALL_FACTS,
    SAFE_TOP,
    type CardAspect,
    type CardFactsPayload,
} from '@/lib/card/types';
import { makeCardFacts } from '@/test/cardFacts';

function render(
    overrides: Partial<CardFactsPayload> = {},
    aspect: CardAspect = 'story',
): string {
    return renderBroadsheet(
        printFacts(makeCardFacts(overrides), ALL_FACTS),
        aspect,
    );
}

/** The value set in the one italic display face — the card's hero figure. */
function hero(svg: string): string {
    return (
        /font-family="Fraunces" font-size="\d{3}(?:\.\d+)?"[^>]*>([^<]+)</.exec(
            svg,
        )?.[1] ??
        ''
    );
}

describe('broadsheet', () => {
    it('sets the masthead with the wordmark, the rarity word and the meta line', () => {
        const svg = render();

        expect(svg).toContain('>temari<');
        expect(svg).toContain('COMMON');
        expect(svg).toContain('SENAYAN · 29°C');
        expect(svg).toContain('SUN 13 SEP 2026');
    });

    it('makes distance the hero on a normal run and finish time on a race or a PR', () => {
        expect(hero(render())).toBe('5.28');
        expect(hero(render({ form: 'race', race_name: 'CITY 10K' }))).toBe(
            '32:18',
        );
        expect(hero(render({ form: 'pr' }))).toBe('32:18');
    });

    it('right-anchors the unit mark at the far margin rather than trailing the figure', () => {
        expect(render()).toContain(
            'x="996" y="1370" font-family="JetBrains Mono" font-size="62" font-weight="600" text-anchor="end"',
        );
    });

    it('fits the hero to the room it has instead of stepping a fixed ladder', () => {
        const sizeOf = (svg: string) =>
            Number(
                /font-family="Fraunces" font-size="(\d{3}(?:\.\d+)?)"/.exec(svg)?.[1] ??
                    0,
            );

        // A wider figure takes a smaller size; a narrow one is never inflated
        // past the style's nominal.
        expect(sizeOf(render({ km: '188.40' }))).toBeLessThan(
            sizeOf(render({ km: '5.28' })),
        );
        expect(sizeOf(render({ km: '5.28' }))).toBeLessThanOrEqual(400);
    });

    it('replaces the masthead with a rarity band on a race, inside the safe zone', () => {
        const svg = render({
            form: 'race',
            rarity: 'rare',
            race_name: 'JAKARTA CITY 10K',
            race_distance: '10K',
        });

        expect(svg).toContain('JAKARTA CITY 10K');
        expect(svg).toContain('BIB 0418');
        expect(svg).toContain(`y="${SAFE_TOP}" width="1080" height="104"`);
    });

    it('turns the route into a typographic belt when there is no trace', () => {
        const svg = render({ polyline: null, form: 'nogps' });

        expect(svg).toContain('NO GPS · NO ROUTE');
        expect(svg).not.toContain('stroke-width="11"');
    });

    it('promotes the route and rules the edge down the right on a long run', () => {
        const svg = render({ form: 'long' });

        expect(svg).toContain('stroke-width="16"');
        expect(svg).toContain('LONG RUN');
        expect(svg).toContain('rotate(90)');
    });

    it('escalates its chrome one additive layer per rarity tier', () => {
        const edgeBar = 'y="270" width="10"';
        const wedge = 'M0,1920 L0,806.4';

        expect(render({ rarity: 'common' })).not.toContain(edgeBar);
        expect(render({ rarity: 'uncommon' })).toContain(edgeBar);
        expect(render({ rarity: 'uncommon' })).not.toContain(wedge);
        expect(render({ rarity: 'epic' })).toContain(wedge);
        expect(render({ rarity: 'legendary' })).toContain(
            'width="1080" height="1920" fill="#f5a623"',
        );
    });

    it('warms the ground on a PR and cools it back on anything else', () => {
        expect(render({ form: 'pr' })).toContain(PR_GROUND);
        expect(render()).toContain(SKY_DEEP);
    });

    it('boxes a badge chip around the measured label, or falls back to the serial', () => {
        expect(render({ badges: ['Heat Tamer'] })).toContain('HEAT TAMER');
        expect(render({ badges: [] })).toContain('TMR-0418');
    });

    it("prints a race's splits as a right-anchored column", () => {
        const svg = render({
            form: 'race',
            race_name: 'CITY 10K',
            race_distance: '10K',
            splits: [
                ['2K', '11:08'],
                ['4K', '11:02'],
            ],
        });

        expect(svg).toContain('SPLITS');
        expect(svg).toContain('2K  11:08');
        expect(svg).toContain('4K  11:02');
    });

    it('drops a stat cell it cannot fill rather than ruling an empty one', () => {
        const svg = render({ heart_rate: null, elevation: null, clock: '' });

        expect(svg).toContain('>TIME<');
        expect(svg).toContain('>PACE<');
        expect(svg).not.toContain('>START<');
        expect(svg).not.toContain('>AVG HR<');
    });

    it('draws the feed card at the square size', () => {
        expect(render({}, 'feed')).toContain(
            'width="1080" height="1080" viewBox="0 0 1080 1080"',
        );
    });
});
