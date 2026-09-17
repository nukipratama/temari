import { describe, expect, it } from 'vitest';

import { districtFromLocation } from './helpers';

describe('districtFromLocation', () => {
    it('returns null for null or empty', () => {
        expect(districtFromLocation(null)).toBeNull();
        expect(districtFromLocation('')).toBeNull();
    });

    it('returns the district (2nd segment), skipping the venue', () => {
        expect(
            districtFromLocation(
                'Gelora Bung Karno, Jakarta Pusat, DKI Jakarta, Indonesia',
            ),
        ).toBe('Jakarta Pusat');
    });

    it('falls back to the only segment when there is no district', () => {
        expect(districtFromLocation('Senayan')).toBe('Senayan');
    });
});
