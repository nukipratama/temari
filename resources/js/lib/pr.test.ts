import { describe, expect, it } from 'vitest';
import { PR_CATEGORY_LABELS, formatPrValue } from './pr';

describe('PR_CATEGORY_LABELS', () => {
    it('maps the canonical distance + effort categories to display labels', () => {
        expect(PR_CATEGORY_LABELS['5km']).toBe('5 KM');
        expect(PR_CATEGORY_LABELS.half_marathon).toBe('Half Marathon');
        expect(PR_CATEGORY_LABELS.best_20min).toBe('Best 20 minutes');
        expect(PR_CATEGORY_LABELS.best_30min).toBe('Best 30 minutes');
    });
});

describe('formatPrValue', () => {
    it('formats distance PRs as a hh:mm:ss duration', () => {
        expect(formatPrValue('5km', 1751)).toBe('29:11');
        expect(formatPrValue('half_marathon', 7200)).toBe('2:00:00');
    });

    it('formats effort PRs as min:sec/km pace', () => {
        expect(formatPrValue('best_20min', 320)).toBe('5:20/km');
        expect(formatPrValue('best_60min', 349)).toBe('5:49/km');
    });
});
