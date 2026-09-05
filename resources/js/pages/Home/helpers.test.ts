import { describe, expect, it } from 'vitest';

import { districtFromLocation, formatSignedForm } from './helpers';

describe('formatSignedForm', () => {
    it('prepends + for positive form', () => {
        expect(formatSignedForm(2.3)).toBe('+2.3');
    });

    it('keeps the - sign for negative form', () => {
        expect(formatSignedForm(-1.7)).toBe('-1.7');
    });
});

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
