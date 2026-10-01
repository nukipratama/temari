import { describe, expect, it } from 'vitest';

import type { FormStatus } from '@/types/inertia';

import {
    formatSignedForm,
    formStatusLabel,
    formStatusMeaning,
    formStatusTone,
    formStatusWord,
    loadBalanceOf,
} from './formStatus';

const STATUSES: FormStatus[] = ['fresh', 'optimal', 'fatigued', 'overreaching'];

describe('formStatusLabel', () => {
    it.each([
        ['fresh', 'fresh'],
        ['optimal', 'steady'],
        ['fatigued', 'heavy'],
        ['overreaching', 'heavy'],
    ] satisfies Array<[FormStatus, string]>)('maps %s → %s', (s, label) => {
        expect(formStatusLabel(s)).toBe(label);
    });

    it('returns dash for null', () => {
        expect(formStatusLabel(null)).toBe('—');
    });
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
        ['optimal', 'steady', 'neutral'],
        ['fatigued', 'heavy', 'warning'],
        ['overreaching', 'heavy', 'warning'],
    ] satisfies Array<[FormStatus, string, string]>)(
        'maps %s to word %s and tone %s',
        (status, word, tone) => {
            expect(formStatusWord(status)).toBe(word);
            expect(formStatusTone(status)).toBe(tone);
            expect(loadBalanceOf(status)).toBe(word);
            expect(formStatusMeaning(status)).not.toBe('');
        },
    );

    it('points a heavy balance at other causes and at telling temari', () => {
        const meaning = formStatusMeaning('fatigued');

        expect(meaning).toContain('illness');
        expect(meaning).toContain('under-fuelling');
        expect(meaning).toContain('tell temari how you feel');
        expect(formStatusMeaning('overreaching')).toBe(meaning);
    });

    it.each(STATUSES)(
        'keeps physiology wording out of the %s meaning',
        (status) => {
            expect(formStatusMeaning(status)).not.toMatch(
                /injury|sore|fitness|readiness|overreach|adaptation|breaking down/i,
            );
        },
    );
});
