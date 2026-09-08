import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    anchorElementId,
    anchorLabel,
    drawnHomeAnchors,
    drawnRunAnchors,
    revealAnchor,
    showsDecoupling,
    showsGrade,
} from './anchors';

describe('anchorElementId', () => {
    it('maps each namespace to the element that draws it', () => {
        expect(anchorElementId('split:4')).toBe('anchor-split-4');
        expect(anchorElementId('session:today')).toBe('anchor-session-today');
        expect(anchorElementId('zone:z3')).toBe('anchor-zone-z3');
        expect(anchorElementId('metric:gap_pace')).toBe(
            'anchor-metric-gap_pace',
        );
    });

    it('rejects anything outside the namespace the narrator validates', () => {
        expect(anchorElementId('split:0')).toBeNull();
        expect(anchorElementId('zone:z6')).toBeNull();
        expect(anchorElementId('metric:VO2MAX')).toBeNull();
        expect(anchorElementId('https://example.com')).toBeNull();
        expect(anchorElementId('')).toBeNull();
    });
});

describe('anchorLabel', () => {
    it('names the target in the reader words, not the anchor grammar', () => {
        expect(anchorLabel('split:4')).toBe('km 4');
        expect(anchorLabel('zone:z3')).toBe('zone 3');
        expect(anchorLabel('metric:pace_variability')).toBe('pace variability');
    });

    it('has no label for an anchor it cannot parse', () => {
        expect(anchorLabel('nonsense')).toBeNull();
    });
});

describe('showsGrade', () => {
    it('is false for a flat run, so a flat run shows no 0% tile', () => {
        expect(showsGrade({ max_grade_pct: 2 })).toBe(false);
        expect(showsGrade({})).toBe(false);
    });

    it('is false for an unusable reading rather than rendering NaN', () => {
        expect(showsGrade({ max_grade_pct: 'oops' })).toBe(false);
    });

    it('is true once the run actually climbed', () => {
        expect(showsGrade({ max_grade_pct: 3 })).toBe(true);
    });
});

describe('showsDecoupling', () => {
    it('reads a real zero as a reading, not as absence', () => {
        expect(showsDecoupling({ decoupling_pct: 0 })).toBe(true);
    });

    it('is false when the run never measured it', () => {
        expect(showsDecoupling({})).toBe(false);
        expect(showsDecoupling({ decoupling_pct: 'oops' })).toBe(false);
    });
});

describe('drawnRunAnchors', () => {
    it('draws one anchor per whole split the chart plotted', () => {
        const drawn = drawnRunAnchors({}, 3, null);

        expect([...drawn]).toEqual(['split:1', 'split:2', 'split:3']);
    });

    it('draws only the zones the legend lists', () => {
        const drawn = drawnRunAnchors({}, 0, { Z1: 40, Z2: 60, Z3: 0 });

        expect(drawn.has('zone:z1')).toBe(true);
        expect(drawn.has('zone:z2')).toBe(true);
        expect(drawn.has('zone:z3')).toBe(false);
    });

    /**
     * The flat-pace tile is nested inside the grade tile's own condition, so a
     * flat run with a gap_pace reading still draws neither.
     */
    it('withholds flat pace on a run too flat to show a grade', () => {
        const drawn = drawnRunAnchors(
            { max_grade_pct: 1, gap_pace: '5:30' },
            0,
            null,
        );

        expect(drawn.has('metric:grade')).toBe(false);
        expect(drawn.has('metric:gap_pace')).toBe(false);
    });

    it('draws both once the run climbed', () => {
        const drawn = drawnRunAnchors(
            { max_grade_pct: 6, gap_pace: '5:30' },
            0,
            null,
        );

        expect(drawn.has('metric:grade')).toBe(true);
        expect(drawn.has('metric:gap_pace')).toBe(true);
    });

    /**
     * These four validate server-side against the run's StreamSummary but no
     * component on the run page draws them, so a claim about one must offer no
     * control rather than one that goes nowhere.
     */
    it('draws none of the metrics the run page has no site for', () => {
        const drawn = drawnRunAnchors(
            { max_grade_pct: 6, gap_pace: '5:30', decoupling_pct: 4 },
            5,
            { Z2: 100 },
        );

        for (const metric of [
            'hr_drift',
            'cadence_drop',
            'pace_variability',
            'negative_split',
        ]) {
            expect(drawn.has(`metric:${metric}`)).toBe(false);
        }
    });
});

describe('revealAnchor', () => {
    afterEach(() => {
        vi.useRealTimers();
        document.body.replaceChildren();
    });

    /**
     * Narration is stored, so a claim outlives the page that drew it: a row
     * written before a component was renamed still names an id nobody renders.
     */
    it('does nothing when the element is not on the page', () => {
        expect(() => revealAnchor('split:9')).not.toThrow();
    });

    it('does nothing for an anchor outside the namespace', () => {
        expect(() => revealAnchor('https://example.com')).not.toThrow();
    });

    it('clears the mark once the reader has had time to look', () => {
        vi.useFakeTimers();
        const target = document.createElement('div');
        target.id = 'anchor-zone-z2';
        target.scrollIntoView = vi.fn();
        document.body.append(target);

        revealAnchor('zone:z2');
        expect(target.dataset.anchorHit).toBe('true');

        vi.runAllTimers();
        expect(target.dataset.anchorHit).toBeUndefined();
    });
});

describe('drawnHomeAnchors', () => {
    it('draws the prescribed session only when the plan covers today', () => {
        const today = new Date();
        const iso = [
            today.getFullYear(),
            String(today.getMonth() + 1).padStart(2, '0'),
            String(today.getDate()).padStart(2, '0'),
        ].join('-');

        expect(drawnHomeAnchors({ days: [{ date: iso }] })).toEqual(
            new Set(['session:today']),
        );
        expect(drawnHomeAnchors({ days: [{ date: '1999-01-01' }] })).toEqual(
            new Set(),
        );
        expect(drawnHomeAnchors(null)).toEqual(new Set());
    });
});
