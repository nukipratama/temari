import { describe, expect, it } from 'vitest';

import { isTabId, navTabFor } from './navRoutes';

describe('navRoutes', () => {
    it('maps primary and nested tab pages', () => {
        expect(navTabFor('Home')).toBe('today');
        expect(navTabFor('Race')).toBe('plan');
        expect(navTabFor('Runs/Show')).toBeNull();
    });

    it('accepts only known tab ids', () => {
        expect(isTabId('history')).toBe(true);
        expect(isTabId('Runs/Show')).toBe(false);
        expect(isTabId(null)).toBe(false);
    });
});
