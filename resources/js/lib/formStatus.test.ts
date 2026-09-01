import { describe, expect, it } from 'vitest';

import type { FormStatus } from '@/types/inertia';

import { formStatusLabel } from './formStatus';

describe('formStatusLabel', () => {
    it.each([
        ['fresh', 'feeling fresh'],
        ['optimal', 'right on track'],
        ['fatigued', 'getting tired'],
        ['overreaching', 'overreaching'],
    ] satisfies Array<[FormStatus, string]>)('maps %s → %s', (s, label) => {
        expect(formStatusLabel(s)).toBe(label);
    });

    it('returns dash for null', () => {
        expect(formStatusLabel(null)).toBe('—');
    });
});
