import { describe, expect, it } from 'vitest';

import type { FormStatus } from '@/types/inertia';

import {
    formatSignedForm,
    formStatusFor,
    formStatusLabel,
    formStatusMeaning,
    formStatusTone,
    formStatusWord,
} from './formStatus';

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

describe('formStatusFor', () => {
    // Mirrors TrainingLoad::formStatus()'s threshold table.
    it.each([
        [30, 40, 'fresh'],
        [10, 40, 'optimal'],
        [-10, 40, 'optimal'],
        [-20, 40, 'fatigued'],
        [-50, 40, 'overreaching'],
        [10, 10, 'fresh'], // low-CTL threshold (5) is tighter
        [3, 10, 'optimal'],
    ] satisfies Array<[number, number, FormStatus]>)(
        'form %d at ctl %d → %s',
        (form, ctl, status) => {
            expect(formStatusFor(form, ctl)).toBe(status);
        },
    );
});

describe('formatSignedForm', () => {
    it('prepends + for positive form', () => {
        expect(formatSignedForm(2.3)).toBe('+2.3');
    });

    it('keeps the - sign for negative form', () => {
        expect(formatSignedForm(-1.7)).toBe('-1.7');
    });
});

describe('formStatusWord / formStatusTone / formStatusMeaning', () => {
    it.each([
        ['fresh', 'fresh', 'positive'],
        ['optimal', 'balanced', 'neutral'],
        ['fatigued', 'tired', 'warning'],
        ['overreaching', 'overreaching', 'warning'],
    ] satisfies Array<[FormStatus, string, string]>)(
        'maps %s to word %s and tone %s',
        (status, word, tone) => {
            expect(formStatusWord(status)).toBe(word);
            expect(formStatusTone(status)).toBe(tone);
            expect(formStatusMeaning(status)).not.toBe('');
        },
    );
});
