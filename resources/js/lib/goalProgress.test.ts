import { describe, expect, it } from 'vitest';
import { formatGoalNumber, goalProgressRatio } from './goalProgress';

describe('goalProgressRatio', () => {
    it('returns the clamped current/target ratio', () => {
        expect(goalProgressRatio(5, 10)).toBe(0.5);
    });

    it('caps the ratio at 1 once the goal is met or exceeded', () => {
        expect(goalProgressRatio(12, 10)).toBe(1);
    });

    it('returns 0 for a non-positive target', () => {
        expect(goalProgressRatio(5, 0)).toBe(0);
        expect(goalProgressRatio(5, -3)).toBe(0);
    });
});

describe('formatGoalNumber', () => {
    it('keeps integers whole', () => {
        expect(formatGoalNumber(42)).toBe('42');
    });

    it('renders one decimal for fractional values', () => {
        expect(formatGoalNumber(12.34)).toBe('12.3');
    });
});
