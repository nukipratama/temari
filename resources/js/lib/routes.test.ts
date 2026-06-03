import { describe, expect, it } from 'vitest';
import { aktivitasUrl, kartuUrl } from './routes';
import type { RunCard } from '@/types/inertia';

describe('kartuUrl', () => {
    it('builds the card detail path from the card id', () => {
        expect(kartuUrl({ id: 7 })).toBe('/kartu/7');
    });
});

describe('aktivitasUrl', () => {
    it('reads activity_id from a row that carries it', () => {
        expect(aktivitasUrl({ activity_id: 42 })).toBe('/aktivitas/42');
    });

    it('reads id from an Activity', () => {
        expect(aktivitasUrl({ id: 99 })).toBe('/aktivitas/99');
    });
});

describe('guardrail: the same RunCard yields two distinct URLs', () => {
    it('routes a card to its card page and its run to the activity page', () => {
        const card: RunCard = { id: 5, activity_id: 88, rarity: 'rare', special_move: 'X', badges: null };
        expect(kartuUrl(card)).toBe('/kartu/5');
        expect(aktivitasUrl(card)).toBe('/aktivitas/88');
    });
});
