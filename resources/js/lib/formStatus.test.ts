import { describe, expect, it } from 'vitest';
import { formStatusLabel, formStatusTone } from './formStatus';
import type { FormStatus } from '@/types/inertia';

describe('formStatusLabel', () => {
    it.each([
        ['fresh', 'Lagi seger'],
        ['optimal', 'Pas banget'],
        ['fatigued', 'Mulai capek'],
        ['overreaching', 'Kelewatan'],
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
