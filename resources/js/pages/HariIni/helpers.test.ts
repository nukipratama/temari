import { describe, expect, it } from 'vitest';
import {
    MOOD_UPPER,
    atlHint,
    ctlHint,
    formatIdDateUpper,
    formatSignedForm,
    formatWeather,
    kartuStripItem,
    monotonyHint,
    pickFeaturedKartu,
    poseForRun,
    shortenLocation,
    strainHint,
    vibeSubtitleFor,
} from './helpers';
import type { ActivityDetail, Rarity } from '@/types/inertia';

function runWith(overrides: Partial<ActivityDetail>, cardOverrides?: { rarity?: Rarity; id?: number; special_move?: string }): ActivityDetail {
    return {
        id: overrides.id ?? 1,
        activity_id: overrides.activity_id ?? 99,
        name: 'Lari',
        start_date_local: '2026-05-20T07:00',
        distance: 5000,
        moving_time: 1800,
        trimp_edwards: 60,
        average_heartrate: 145,
        ...overrides,
        activity: cardOverrides
            ? {
                id: 99,
                user_id: 1,
                analyzed_at: '2026-05-20',
                run_card: {
                    id: cardOverrides.id ?? 7,
                    activity_id: 99,
                    rarity: cardOverrides.rarity ?? 'common',
                    special_move: cardOverrides.special_move ?? 'Langkah Mantap',
                    badges: ['negative_split'],
                },
            }
            : undefined,
    } as ActivityDetail;
}

describe('formatSignedForm', () => {
    it('prepends + for positive form', () => {
        expect(formatSignedForm(2.3)).toBe('+2.3');
    });

    it('keeps the - sign for negative form', () => {
        expect(formatSignedForm(-1.7)).toBe('-1.7');
    });
});

describe('vibeSubtitleFor', () => {
    it('lowercases the vibe label and wraps it in "kamu lagi …"', () => {
        expect(vibeSubtitleFor('Membara')).toBe('kamu lagi membara.');
    });
});

describe('poseForRun', () => {
    it('maps moodFromActivity output to a Temari pose', () => {
        // A high-effort run reads as nyala → proud pose.
        const run = runWith({ trimp_edwards: 200, distance: 12_000, moving_time: 3_600, average_heartrate: 170 });
        expect(poseForRun(run)).toMatch(/proud|excited|wobble|reading|observational/);
    });
});

describe('pickFeaturedKartu', () => {
    it('returns null when no run has an attached card', () => {
        expect(pickFeaturedKartu([runWith({})])).toBeNull();
    });

    it('picks the highest-rarity card; ties broken by most recent date', () => {
        const older = runWith(
            { id: 1, activity_id: 1, start_date_local: '2026-05-01' },
            { id: 1, rarity: 'epic', special_move: 'Older Epic' },
        );
        const newer = runWith(
            { id: 2, activity_id: 2, start_date_local: '2026-05-20' },
            { id: 2, rarity: 'epic', special_move: 'Newer Epic' },
        );
        const rare = runWith(
            { id: 3, activity_id: 3, start_date_local: '2026-05-21' },
            { id: 3, rarity: 'rare', special_move: 'Rare One' },
        );
        const featured = pickFeaturedKartu([older, newer, rare]);
        expect(featured?.name).toBe('Newer Epic');
    });
});

describe('kartuStripItem', () => {
    it('returns null when the run has no attached card', () => {
        expect(kartuStripItem(runWith({}))).toBeNull();
    });

    it('returns a strip item with rarity + key derived from card id', () => {
        const run = runWith({}, { id: 42, rarity: 'rare', special_move: 'Cool Move' });
        const item = kartuStripItem(run);
        expect(item).toMatchObject({ key: 'card-42', name: 'Cool Move', rarity: 'rare' });
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

describe('MOOD_UPPER', () => {
    it('uppercases every mood value', () => {
        expect(MOOD_UPPER.nyala).toBe('NYALA');
        expect(MOOD_UPPER.adem).toBe('ADEM');
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
        expect(shortenLocation('Senayan, Jakarta Pusat, DKI Jakarta, Indonesia'))
            .toBe('Senayan, Jakarta Pusat');
    });

    it('skips empty segments', () => {
        expect(shortenLocation(',,Senayan,,')).toBe('Senayan');
    });
});

describe('formatWeather', () => {
    it('returns null when no fields are present', () => {
        expect(formatWeather(null, null, null)).toBeNull();
        expect(formatWeather(null, null, false)).toBeNull();
    });

    it('formats temperature, humidity, and rain when present', () => {
        expect(formatWeather(28.4, 75, true)).toBe('28°C · 75% · hujan');
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
        expect(ctlHint(10)).toBe('lagi dibangun');
        expect(ctlHint(30)).toBe('naik tipis');
        expect(ctlHint(60)).toBe('stabil');
        expect(ctlHint(100)).toBe('tinggi');
    });
});

describe('atlHint', () => {
    it('returns empty for null', () => {
        expect(atlHint(null)).toBe('');
    });

    it('classifies atl by threshold', () => {
        expect(atlHint(10)).toBe('fresh');
        expect(atlHint(40)).toBe('wajar');
        expect(atlHint(70)).toBe('lelah');
        expect(atlHint(100)).toBe('berat');
    });
});

describe('strainHint', () => {
    it('returns empty for null', () => {
        expect(strainHint(null)).toBe('');
    });

    it('classifies strain by threshold', () => {
        expect(strainHint(100)).toBe('ringan');
        expect(strainHint(300)).toBe('sedang');
        expect(strainHint(600)).toBe('berat');
    });
});

describe('monotonyHint', () => {
    it('returns empty for null', () => {
        expect(monotonyHint(null)).toBe('');
    });

    it('classifies monotony by threshold', () => {
        expect(monotonyHint(1.2)).toBe('sehat');
        expect(monotonyHint(1.7)).toBe('tinggi');
        expect(monotonyHint(2.5)).toBe('monoton');
    });
});
