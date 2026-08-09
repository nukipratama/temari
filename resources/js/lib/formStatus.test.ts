import { describe, expect, it } from 'vitest';

import type { FormStatus } from '@/types/inertia';

import { formStatusLabel, formStatusTone } from './formStatus';

describe('formStatusLabel', () => {
    it.each([
        ['fresh', 'Feeling Fresh'],
        ['optimal', 'Right on Track'],
        ['fatigued', 'Getting Tired'],
        ['overreaching', 'Overreaching'],
    ] satisfies Array<[FormStatus, string]>)('maps %s → %s', (s, label) => {
        expect(formStatusLabel(s)).toBe(label);
    });

    it('returns dash for null', () => {
        expect(formStatusLabel(null)).toBe('—');
    });
});

describe('formStatusTone', () => {
    it('maps fresh → positive', () => {
        expect(formStatusTone('fresh')).toBe('positive');
    });

    it('maps fatigued → warning', () => {
        expect(formStatusTone('fatigued')).toBe('warning');
    });

    it('maps overreaching → alert', () => {
        expect(formStatusTone('overreaching')).toBe('alert');
    });

    it('maps optimal → neutral', () => {
        expect(formStatusTone('optimal')).toBe('neutral');
    });

    it('returns neutral for null', () => {
        expect(formStatusTone(null)).toBe('neutral');
    });
});
