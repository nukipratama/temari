import { describe, expect, it } from 'vitest';

import { preferencesSummary } from './options';

describe('Onboarding/options preferencesSummary', () => {
    it('is empty when every question was skipped', () => {
        expect(preferencesSummary(null, null, null)).toBe('');
    });

    it('joins the answered preferences with a middle dot', () => {
        expect(preferencesSummary('experienced', 4, 'race')).toBe(
            'experienced · 4x a week · chase a race time',
        );
    });

    it('leaves out the unanswered ones', () => {
        expect(preferencesSummary(null, 3, null)).toBe('3x a week');
    });
});
