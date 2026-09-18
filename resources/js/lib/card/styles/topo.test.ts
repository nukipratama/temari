import { describe, expect, it } from 'vitest';

import { printFacts } from '@/lib/card/facts';
import { CITRUS, INK, LEAF_INK } from '@/lib/card/palette';
import { renderTopoPlate } from '@/lib/card/styles/topo';
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
    return renderTopoPlate(
        printFacts(makeCardFacts(overrides), ALL_FACTS),
        aspect,
    );
}

function occurrences(haystack: string, needle: string): number {
    return haystack.split(needle).length - 1;
}

describe('topo plate', () => {
    it('frames the plate with a ticked collar, a scale bar and a north arrow', () => {
        const svg = render();

        expect(svg).toContain('PLATE 0418 · SENAYAN');
        expect(svg).toContain('>N<');
        expect(svg).toContain('>2 KM<');
        expect(svg).toContain('>temari<');
    });

    it('rules the title block with distance, time and pace, then the metadata row', () => {
        const svg = render();

        expect(svg).toContain('>5.28 KM<');
        expect(svg).toContain('>32:18<');
        expect(svg).toContain('>6:07/K<');
        expect(svg).toContain('>13.09.26<');
        expect(svg).toContain('>05:41<');
        expect(svg).toContain('>142<');
        expect(svg).toContain('>18 M<');
    });

    it('drops a toggled-off cell from the metadata row rather than printing a gap', () => {
        const svg = render({ heart_rate: null, elevation: null });

        expect(svg).not.toContain('AVG HR');
        expect(svg).not.toContain('>ELEV<');
    });

    it('relabels time and gates the finish on a race', () => {
        const svg = render({
            form: 'race',
            rarity: 'rare',
            race_name: 'CITY 10K',
            race_distance: '10K',
            splits: [['2K', '11:08']],
        });

        expect(svg).toContain('>FINISH<');
        expect(svg).toContain('>SPLITS<');
        expect(svg).toContain('>11:08<');
        expect(svg).not.toContain('>TIME<');
    });

    it('surveys nothing when there is no trace', () => {
        const svg = render({ polyline: null, form: 'nogps' });

        expect(svg).toContain('UNSURVEYED');
        expect(svg).toContain('NO TRACE · 5.28 KM');
        expect(svg).not.toContain('stroke-width="22"');
        expect(svg).not.toContain(LEAF_INK);
    });

    it('numbers the kilometres along a long run and coarsens its scale bar', () => {
        const svg = render({ form: 'long' });

        expect(svg).toContain('>4 KM<');
        expect(svg).toContain('stroke-width="4"');
    });

    it("records the run's own shape rather than an invented terrain profile", () => {
        expect(render({ form: 'long', pace_profile: [0, 1, 0.5] })).toContain(
            'PACE PROFILE',
        );
        expect(render({ form: 'long' })).not.toContain('PACE PROFILE');
    });

    it('escalates the survey density with the rarity tier', () => {
        expect(
            occurrences(render({ rarity: 'common' }), `stroke="${LEAF_INK}"`),
        ).toBe(7);
        expect(
            occurrences(
                render({ rarity: 'legendary' }),
                `stroke="${LEAF_INK}"`,
            ),
        ).toBe(15);
    });

    it('tints under the trace and promotes it to the rarity colour from rare up', () => {
        expect(render({ rarity: 'uncommon' })).toContain(
            `stroke="${INK}" stroke-width="10"`,
        );
        expect(render({ rarity: 'rare' })).toContain(
            `stroke="${RARITY_HEX.rare}" stroke-width="10"`,
        );
        expect(render({ rarity: 'rare' })).toContain(
            `fill="${RARITY_HEX.rare}" fill-opacity="0.07"`,
        );
    });

    it('stamps the survey and centre-lines the trace from epic up', () => {
        const svg = render({ rarity: 'epic' });

        expect(svg).toContain('CERTIFIED');
        expect(svg).toContain('stroke-dasharray="2 22"');

        const pr = render({ form: 'pr', rarity: 'epic' });
        expect(pr).toContain('NEW BEST');
        expect(pr).toContain(CITRUS);
    });

    it('keeps the legend from colliding: whole badges only, never half a name', () => {
        const svg = render({ badges: ['Speedster', 'Negative Split'] });

        expect(svg).toContain('>SPEEDSTER<');
        expect(svg).not.toContain('NEGATIVE SPL<');
    });

    it('rules no metadata row at all when every cell in it is empty', () => {
        const svg = render({
            polyline: null,
            form: 'nogps',
            heart_rate: null,
            elevation: null,
            date_short: '',
            clock: '',
        });

        expect(svg).not.toContain('>DATE<');
        expect(svg).not.toContain('>START<');
        expect(svg).not.toContain('AVG HR');
        expect(svg).not.toContain('>ELEV<');
        expect(svg).toContain('>DISTANCE<');
        expect(svg).toContain('UNSURVEYED');
    });

    it('leaves no caption behind a legend line it has no value for', () => {
        const svg = render({ weather: null, badges: [] });

        expect(svg).not.toContain('<text></text>');
        expect(svg).toContain('>temari<');
    });

    it('sits the plate inside the story safe zone and fills the square feed card', () => {
        expect(render()).toContain('y="270" width="972" height="1378"');
        expect(render({}, 'feed')).toContain(
            'width="1080" height="1080" viewBox="0 0 1080 1080"',
        );
    });
});
