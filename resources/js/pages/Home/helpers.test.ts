import { describe, expect, it } from 'vitest';

import {
    atlHint,
    atlTone,
    ctlHint,
    districtFromLocation,
    formatIdDateUpper,
    formatSignedForm,
    formatWeather,
    monotonyHint,
    monotonyTone,
    shortenLocation,
    strainHint,
    strainTone,
} from './helpers';

describe('formatSignedForm', () => {
    it('prepends + for positive form', () => {
        expect(formatSignedForm(2.3)).toBe('+2.3');
    });

    it('keeps the - sign for negative form', () => {
        expect(formatSignedForm(-1.7)).toBe('-1.7');
    });
});

describe('formatIdDateUpper', () => {
    it('returns empty for null', () => {
        expect(formatIdDateUpper(null)).toBe('');
    });

    it('returns empty for invalid ISO', () => {
        expect(formatIdDateUpper('not-a-date')).toBe('');
    });

    it('uppercases the id-ID short weekday + day + month', () => {
        const out = formatIdDateUpper('2026-05-20T07:00');
        expect(out).toMatch(/^[A-Z]/);
        expect(out).toBe(out.toUpperCase());
    });
});

describe('shortenLocation', () => {
    it('returns null for null or empty', () => {
        expect(shortenLocation(null)).toBeNull();
        expect(shortenLocation('')).toBeNull();
    });

    it('returns the only segment when there is just one', () => {
        expect(shortenLocation('Senayan')).toBe('Senayan');
    });

    it('keeps only the first two comma-separated segments', () => {
        expect(
            shortenLocation('Senayan, Jakarta Pusat, DKI Jakarta, Indonesia'),
        ).toBe('Senayan, Jakarta Pusat');
    });

    it('skips empty segments', () => {
        expect(shortenLocation(',,Senayan,,')).toBe('Senayan');
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

describe('formatWeather', () => {
    it('returns null when no fields are present', () => {
        expect(formatWeather(null, null, null)).toBeNull();
        expect(formatWeather(null, null, false)).toBeNull();
    });

    it('formats temperature, humidity, and rain when present', () => {
        expect(formatWeather(28.4, 75, true)).toBe('28°C · 75% · rain');
    });

    it('omits rain when false', () => {
        expect(formatWeather(28, 75, false)).toBe('28°C · 75%');
    });
});

describe('ctlHint', () => {
    it('returns empty for null', () => {
        expect(ctlHint(null)).toBe('');
        expect(ctlHint(undefined)).toBe('');
    });

    it('classifies ctl by threshold', () => {
        expect(ctlHint(10)).toBe('still building');
        expect(ctlHint(30)).toBe('trending up');
        expect(ctlHint(60)).toBe('stable');
        expect(ctlHint(100)).toBe('high');
    });
});

describe('atlHint', () => {
    it('returns empty for null', () => {
        expect(atlHint(null)).toBe('');
    });

    it('classifies atl by threshold', () => {
        expect(atlHint(10)).toBe('fresh');
        expect(atlHint(40)).toBe('normal');
        expect(atlHint(70)).toBe('tired');
        expect(atlHint(100)).toBe('heavy');
    });
});

describe('strainHint', () => {
    it('returns empty for null', () => {
        expect(strainHint(null)).toBe('');
    });

    it('classifies strain by threshold', () => {
        expect(strainHint(100)).toBe('light');
        expect(strainHint(300)).toBe('moderate');
        expect(strainHint(600)).toBe('heavy');
    });
});

describe('monotonyHint', () => {
    it('returns empty for null', () => {
        expect(monotonyHint(null)).toBe('');
    });

    it('classifies monotony by threshold', () => {
        expect(monotonyHint(1.2)).toBe('healthy');
        expect(monotonyHint(1.7)).toBe('high');
        expect(monotonyHint(2.5)).toBe('monotonous');
    });
});

describe('atlTone / strainTone / monotonyTone', () => {
    it('reads calm for null (nothing to warn about yet)', () => {
        expect(atlTone(null)).toBe('text-leaf-ink');
        expect(strainTone(null)).toBe('text-leaf-ink');
        expect(monotonyTone(null)).toBe('text-leaf-ink');
    });

    it('escalates atl from calm to alert with the same buckets as atlHint', () => {
        expect(atlTone(10)).toBe('text-leaf-ink');
        expect(atlTone(70)).toBe('text-citrus-ink');
        expect(atlTone(100)).toBe('text-ember-ink');
    });

    it('escalates strain from calm to alert with the same buckets as strainHint', () => {
        expect(strainTone(100)).toBe('text-leaf-ink');
        expect(strainTone(300)).toBe('text-citrus-ink');
        expect(strainTone(600)).toBe('text-ember-ink');
    });

    // Regression: monotony >2.0 is the same hard-flag threshold Readiness caps
    // a session for, but the training-load card used to render this row a fixed
    // leaf/green regardless of value — the loudest state read as the calmest.
    it('reads monotony >2.0 as alert, matching the Readiness hard-flag threshold', () => {
        expect(monotonyTone(1.2)).toBe('text-leaf-ink');
        expect(monotonyTone(1.7)).toBe('text-citrus-ink');
        expect(monotonyTone(3.15)).toBe('text-ember-ink');
    });
});
