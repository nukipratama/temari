import { describe, expect, it } from 'vitest';

import { hasFact, printFacts, statCells, tracePoints } from '@/lib/card/facts';
import { ALL_FACTS } from '@/lib/card/types';
import { makeCardFacts } from '@/test/cardFacts';

const facts = (overrides = {}, options = ALL_FACTS) =>
    printFacts(makeCardFacts(overrides), options);

describe('print facts', () => {
    it('reads the rarity ladder as a level the styles escalate on', () => {
        expect(facts({ rarity: 'common' }).level).toBe(1);
        expect(facts({ rarity: 'legendary' }).level).toBe(5);
        expect(facts({ rarity: 'rare' }).rarityLabel).toBe('Rare');
    });

    it('prints the serial in the bib slot, since no bib is recorded', () => {
        expect(facts().bib).toBe('0418');
    });

    it('withholds a fact whose chip is off, and keeps the rest', () => {
        const off = facts(
            {},
            { hr: false, elevation: false, weather: false, badges: false },
        );

        expect(off.heartRate).toBeNull();
        expect(off.elevation).toBeNull();
        expect(off.weather).toBeNull();
        expect(off.badges).toEqual([]);
        expect(off.km).toBe('5.28');
    });
});

describe('stat cells', () => {
    it('spends the flex cell on elevation for a long run, heart rate otherwise', () => {
        expect(statCells(facts())[2]).toEqual(['AVG HR', '142']);
        expect(statCells(facts({ form: 'long' }))[2]).toEqual(['ELEV', '18 m']);
        expect(statCells(facts({ heart_rate: null }))[2]).toEqual([
            'ELEV',
            '18 m',
        ]);
        expect(
            statCells(facts({ heart_rate: null, elevation: null }))[2],
        ).toEqual(['START', '05:41']);
    });

    it('always leads the row with time and pace', () => {
        const cells = statCells(facts());

        expect(cells[0]).toEqual(['TIME', '32:18']);
        expect(cells[1]).toEqual(['PACE', '6:07/km']);
    });
});

describe('fact availability', () => {
    it('answers whether the run has the fact behind each chip', () => {
        const payload = makeCardFacts();

        expect(hasFact(payload, 'hr')).toBe(true);
        expect(hasFact(payload, 'elevation')).toBe(true);
        expect(hasFact(payload, 'weather')).toBe(true);
        expect(hasFact(payload, 'badges')).toBe(true);

        const bare = makeCardFacts({
            heart_rate: null,
            elevation: null,
            weather: null,
            badges: [],
        });

        expect(hasFact(bare, 'hr')).toBe(false);
        expect(hasFact(bare, 'elevation')).toBe(false);
        expect(hasFact(bare, 'weather')).toBe(false);
        expect(hasFact(bare, 'badges')).toBe(false);
    });
});

describe('trace', () => {
    it('fits the run to the box, and is null when there is nothing drawable', () => {
        const points = tracePoints(makeCardFacts().polyline, 100, 100);

        expect(points).not.toBeNull();
        expect(points!.length).toBeGreaterThan(1);
        expect(tracePoints(null, 100, 100)).toBeNull();
        expect(tracePoints('', 100, 100)).toBeNull();
    });
});
