import { describe, expect, it } from 'vitest';

import {
    ALL_FACTS,
    CARD_ASPECTS,
    CARD_STYLES,
    CARD_WIDTH,
    SAFE_BOTTOM,
    SAFE_TOP,
    cardHeight,
} from '@/lib/card/types';

describe('card shapes', () => {
    it('keeps both exports 1080 wide and only changes the height', () => {
        expect(CARD_WIDTH).toBe(1080);
        expect(cardHeight('story')).toBe(1920);
        expect(cardHeight('feed')).toBe(1080);
    });

    it('holds the story mat inside the bands a story app reserves', () => {
        expect(SAFE_TOP).toBeGreaterThan(0);
        expect(SAFE_BOTTOM).toBeLessThan(cardHeight('story'));
    });

    it('offers three styles, two aspects, and every fact on by default', () => {
        expect(CARD_STYLES).toEqual(['broadsheet', 'ticket', 'topo']);
        expect(CARD_ASPECTS).toEqual(['story', 'feed']);
        expect(Object.values(ALL_FACTS).every(Boolean)).toBe(true);
    });
});
