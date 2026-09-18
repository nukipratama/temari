import { describe, expect, it } from 'vitest';

import { printFacts } from '@/lib/card/facts';
import { CITRUS, CREAM, EMBER, INK } from '@/lib/card/palette';
import { renderTicket } from '@/lib/card/styles/ticket';
import {
    ALL_FACTS,
    type CardAspect,
    type CardFactsPayload,
} from '@/lib/card/types';
import { RARITY_HEX } from '@/lib/runcard';
import { makeCardFacts } from '@/test/cardFacts';

function render(
    overrides: Partial<CardFactsPayload> = {},
    aspect: CardAspect = 'story',
): string {
    return renderTicket(printFacts(makeCardFacts(overrides), ALL_FACTS), aspect);
}

function occurrences(haystack: string, needle: string): number {
    return haystack.split(needle).length - 1;
}

const race = {
    form: 'race',
    race_name: 'JAKARTA CITY 10K',
    race_distance: '10K',
} as const;

describe('ticket', () => {
    it('prints the chassis: an ink band, a perforated tear and a stub', () => {
        const svg = render();

        expect(svg).toContain('filter="url(#ticket-shadow)"');
        expect(svg).toContain(`fill="${RARITY_HEX.common}"`);
        expect(svg).toContain('stroke-dasharray="10 12"');
        expect(svg).toContain('>temari<');
        expect(svg).toContain('SENAYAN, JAKARTA PUSAT');
        expect(svg).toContain('SUN 13 SEP 2026 · 05:41 · 29°C');
    });

    it('sets the whole body in mono, with Fraunces only in the wordmark', () => {
        const svg = render();

        expect(occurrences(svg, 'font-family="Fraunces"')).toBe(1);
        expect(svg).not.toContain('font-family="Plus Jakarta Sans"');
    });

    it('picks the band ink by luminance so both ends of the ladder stay legible', () => {
        expect(render({ rarity: 'legendary' })).toContain(
            `fill="${INK}">EASY RUN`,
        );
        expect(render({ rarity: 'rare' })).toContain(
            `fill="${CREAM}">EASY RUN`,
        );
    });

    it('turns the chassis into a bib on a race', () => {
        const svg = render({ ...race, rarity: 'rare' });

        expect(svg).toContain('JAKARTA CITY 10K');
        expect(svg).toContain(
            'font-size="340" font-weight="800" text-anchor="middle" fill="#16181b">0418',
        );
        expect(svg).toContain('>10K<');
        expect(svg).toContain('FINISH');
    });

    it("gives a race's feed card its chip splits instead of a route window", () => {
        const svg = render(
            {
                ...race,
                splits: [
                    ['2K', '11:08'],
                    ['4K', '11:02'],
                ],
            },
            'feed',
        );

        expect(svg).toContain('CHIP SPLITS');
        expect(svg).toContain('11:08');
        expect(svg).not.toContain('>ROUTE<');
    });

    it('cancels the route window when the run has no trace', () => {
        const svg = render({ polyline: null, form: 'nogps' });

        expect(svg).toContain('NO SIGNAL · NO ROUTE');
        expect(svg).toContain(EMBER);
        expect(svg).not.toContain('>ROUTE<');
    });

    it('stamps a PR across the paper and chips the hero box', () => {
        const svg = render({ form: 'pr', rarity: 'epic' });

        expect(svg).toContain('PERSONAL RECORD');
        expect(svg).toContain('rotate(-8)');
        expect(svg).toContain('NEW BEST');
        expect(svg).toContain(CITRUS);
    });

    it('escalates through print finishing, tier by tier', () => {
        const innerBorder = 'x="72" y="286" width="936"';

        expect(render({ rarity: 'uncommon' })).not.toContain(innerBorder);
        expect(render({ rarity: 'uncommon' })).not.toContain(
            'url(#ticket-foil)',
        );
        expect(render({ rarity: 'rare' })).toContain(innerBorder);
        expect(render({ rarity: 'rare' })).not.toContain('url(#ticket-foil)');
        expect(render({ rarity: 'epic' })).toContain('url(#ticket-foil)');
        expect(
            occurrences(
                render({ rarity: 'legendary' }),
                'stroke-dasharray="10 12"',
            ),
        ).toBe(2);
    });

    it("counts the tier in the stub's rarity dots", () => {
        const dots = (rarity: 'common' | 'epic') =>
            occurrences(
                render({ rarity }),
                `r="8" fill="${RARITY_HEX[rarity]}"`,
            );

        expect(dots('common')).toBe(1);
        expect(dots('epic')).toBe(4);
    });

    it('shrinks the distance figure only as far as the box needs', () => {
        const sizeOf = (svg: string) =>
            Number(/font-size="([\d.]+)" font-weight="800"/.exec(svg)?.[1] ?? 0);

        expect(sizeOf(render({ km: '188.40' }))).toBeLessThan(
            sizeOf(render({ km: '5.28' })),
        );
        expect(sizeOf(render({ km: '5.28' }))).toBeLessThanOrEqual(224);
    });

    it('sits the ticket inside the story safe zone and fills the square feed card', () => {
        expect(render()).toContain('y="272" width="964" height="1368"');
        expect(render({}, 'feed')).toContain(
            'width="1080" height="1080" viewBox="0 0 1080 1080"',
        );
    });

    it('reflows the race feed to the route window when there are no chip splits', () => {
        const svg = render(race, 'feed');

        expect(svg).not.toContain('CHIP SPLITS');
        expect(svg).toContain('>ROUTE<');
    });

    it('drops a stat cell it cannot fill rather than ruling an empty one', () => {
        const svg = render({ heart_rate: null, elevation: null, clock: '' });

        expect(svg).toContain('>TIME<');
        expect(svg).toContain('>PACE<');
        expect(svg).not.toContain('>START<');
        expect(svg).not.toContain('>AVG HR<');
    });

    it('boxes a badge chip around the measured label', () => {
        expect(render({ badges: ['Heat Tamer'] })).toContain('HEAT TAMER');
    });

    it('draws the story body, the feed body and the race bib at both aspects', () => {
        expect(render({}, 'feed')).toContain('>DISTANCE<');
        expect(render({ form: 'pr' }, 'feed')).toContain('NEW BEST');
        expect(render(race)).toContain('>FINISH<');
    });
});
