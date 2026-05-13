import { describe, expect, it } from 'vitest';
import { BADGE_LABELS, RARITY_LABELS, RARITY_ORDER } from './runcard';

describe('RARITY_LABELS', () => {
    it('has label for every rarity in RARITY_ORDER', () => {
        RARITY_ORDER.forEach((r) => {
            expect(RARITY_LABELS[r]).toBeTruthy();
        });
    });

    it('contains all 5 rarities', () => {
        expect(RARITY_ORDER).toHaveLength(5);
    });
});

describe('BADGE_LABELS', () => {
    it('has expected badge keys', () => {
        const expected = ['hari_panas', 'pejuang_hujan', 'anak_pagi', 'long_slow_distance', 'negative_split', 'tahan_diri'];
        expected.forEach((key) => {
            expect(BADGE_LABELS[key]).toBeTruthy();
        });
    });
});
