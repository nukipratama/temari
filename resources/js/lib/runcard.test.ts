import { describe, expect, it } from 'vitest';

import type { ActivityDetail } from '@/types/inertia';

import {
    BADGE_ABILITY,
    BADGE_LABELS,
    RARITY_BAND_COUNT,
    RARITY_INK,
    RARITY_LABELS,
    RARITY_ORDER,
    RARITY_TEXT,
    avgCadenceFromDetail,
    badgeEmblem,
    badgeName,
    fastestKmFromDetail,
    kartuPropsFromDetail,
    threadBandLines,
    zonePctFromDetail,
} from './runcard';

function detailWith(summary: ActivityDetail['stream_summary']): ActivityDetail {
    return {
        id: 1,
        activity_id: 1,
        name: null,
        start_date_local: null,
        distance: null,
        elapsed_time: null,
        average_heartrate: null,
        trimp_edwards: null,
        stream_summary: summary,
    };
}

describe('RARITY_LABELS', () => {
    it('has label for every rarity in RARITY_ORDER', () => {
        RARITY_ORDER.forEach((r) => {
            expect(RARITY_LABELS[r]).toBeTruthy();
        });
    });

    it('contains all 5 rarities', () => {
        expect(RARITY_ORDER).toHaveLength(5);
    });

    // Parity guard: mirrored in App\Enums\Rarity::label() (see RarityTest.php).
    // Changing the ladder on one runtime without the other fails a test.
    it('exposes the rarity ladder labels', () => {
        expect(RARITY_LABELS).toEqual({
            common: 'Common',
            uncommon: 'Uncommon',
            rare: 'Rare',
            epic: 'Epic',
            legendary: 'Legendary',
        });
    });
});

// The fill-vs-text rule from docs/design-tokens.md, as a test: the vivid value
// is the fill, the `-ink` value is the only one that may carry text on paper.
// Shipping `text-rarity-legendary` on a cream surface is the bug this catches.
describe('rarity text colours', () => {
    it('keeps RARITY_TEXT on the vivid fill, for the card frame only', () => {
        RARITY_ORDER.forEach((r) => {
            expect(RARITY_TEXT[r]).toBe(`text-rarity-${r}`);
        });
    });

    it('gives RARITY_INK the -ink variant for every tier', () => {
        RARITY_ORDER.forEach((r) => {
            expect(RARITY_INK[r]).toBe(`text-rarity-${r}-ink`);
        });
    });
});

// Parity guard: mirrored in App\Enums\Rarity::bandCount() (see RarityTest.php).
describe('RARITY_BAND_COUNT', () => {
    it('scales from 1 (common) to 5 (legendary)', () => {
        expect(RARITY_BAND_COUNT).toEqual({
            common: 1,
            uncommon: 2,
            rare: 3,
            epic: 4,
            legendary: 5,
        });
    });
});

describe('threadBandLines', () => {
    it('draws one stitch leaning the same way for count 1', () => {
        const lines = threadBandLines(1);
        expect(lines).toHaveLength(1);
        expect(lines[0]).toMatchObject({ y1: 1, y2: 0 });
    });

    it('adds a second, opposite-leaning crossing set from count 4 on', () => {
        const epic = threadBandLines(4);
        const legendary = threadBandLines(5);
        expect(epic).toHaveLength(4);
        expect(legendary).toHaveLength(5);

        const crossing = (lines: ReturnType<typeof threadBandLines>) =>
            lines.filter((l) => l.y1 === 0);
        expect(crossing(epic)).toHaveLength(1);
        expect(crossing(legendary)).toHaveLength(2);
    });
});

const BADGE_KEYS = [
    'heat_tamer',
    'rain_warrior',
    'early_bird',
    'long_slow_distance',
    'negative_split',
    'held_back',
];

describe('BADGE_LABELS', () => {
    it('has expected badge keys', () => {
        BADGE_KEYS.forEach((key) => {
            expect(BADGE_LABELS[key]).toBeTruthy();
        });
    });

    it('uses the casual English names', () => {
        expect(BADGE_LABELS.heat_tamer).toBe('🔥 Heat Tamer');
        expect(BADGE_LABELS.held_back).toBe('🧘 Held Back');
        expect(BADGE_LABELS.negative_split).toBe('👻 Negative Split');
    });
});

describe('BADGE_ABILITY', () => {
    it('has a one-line meaning for every badge, with no em-dashes', () => {
        BADGE_KEYS.forEach((key) => {
            expect(BADGE_ABILITY[key]).toBeTruthy();
            expect(BADGE_ABILITY[key]).not.toContain('—');
        });
    });
});

describe('badgeEmblem / badgeName', () => {
    it('splits the emoji from the name', () => {
        expect(badgeEmblem('heat_tamer')).toBe('🔥');
        expect(badgeName('heat_tamer')).toBe('Heat Tamer');
        expect(badgeName('held_back')).toBe('Held Back');
    });

    it('falls back to prettyBadge for unknown slugs', () => {
        expect(badgeEmblem('unknown_slug')).toBe('');
        expect(badgeName('unknown_slug')).toBe('Unknown Slug');
    });

    // holiday_run was retired (Slice 2g): an old card can still carry it in
    // its stored badge array, and it must render as inert history, not crash.
    it('renders the retired holiday_run slug without a map entry', () => {
        expect(badgeEmblem('holiday_run')).toBe('');
        expect(badgeName('holiday_run')).toBe('Holiday Run');
    });
});

describe('avgCadenceFromDetail', () => {
    it('averages per-km cadence, rounding, ignoring missing values', () => {
        const detail = detailWith({
            per_km: [
                { km: 1, pace: '6:00', avg_cadence_spm: 176 },
                { km: 2, pace: '5:50', avg_cadence_spm: 180 },
                { km: 3, pace: '5:40', avg_cadence_spm: null },
            ],
        });
        expect(avgCadenceFromDetail(detail)).toBe(178);
    });

    it('returns null when no cadence data exists', () => {
        expect(
            avgCadenceFromDetail(
                detailWith({ per_km: [{ km: 1, pace: '6:00' }] }),
            ),
        ).toBeNull();
        expect(avgCadenceFromDetail(detailWith(undefined))).toBeNull();
    });
});

describe('fastestKmFromDetail', () => {
    it('returns the fastest single-km pace string', () => {
        const detail = detailWith({
            per_km: [
                { km: 1, pace: '6:00' },
                { km: 2, pace: '5:12' },
                { km: 3, pace: '5:45' },
            ],
        });
        expect(fastestKmFromDetail(detail)).toBe('5:12');
    });

    it('returns null without per-km data', () => {
        expect(fastestKmFromDetail(detailWith(undefined))).toBeNull();
    });
});

describe('zonePctFromDetail', () => {
    it('returns the zone distribution when present', () => {
        const detail = detailWith({
            time_in_zone_pct: { Z1: 10, Z2: 50, Z3: 40 },
        });
        expect(zonePctFromDetail(detail)).toEqual({ Z1: 10, Z2: 50, Z3: 40 });
    });

    it('returns null when zone data is absent or all-zero', () => {
        expect(zonePctFromDetail(detailWith(undefined))).toBeNull();
        expect(
            zonePctFromDetail(
                detailWith({ time_in_zone_pct: { Z1: 0, Z2: 0 } }),
            ),
        ).toBeNull();
    });
});

describe('kartuPropsFromDetail', () => {
    const fullDetail: ActivityDetail = {
        id: 1,
        activity_id: 1,
        name: 'Pagi santai',
        start_date_local: '2026-05-11T06:30:00Z',
        distance: 5000,
        elapsed_time: 1810,
        average_heartrate: 152.4,
        trimp_edwards: 42.6,
        stream_summary: {
            per_km: [
                { km: 1, pace: '6:00', avg_cadence_spm: 176 },
                { km: 2, pace: '5:12', avg_cadence_spm: 180 },
            ],
            time_in_zone_pct: { Z1: 10, Z2: 60, Z3: 30 },
        },
    };

    it('derives the full card prop bag with digital HMS duration by default', () => {
        const props = kartuPropsFromDetail(fullDetail);
        expect(props.km).toBe('5.00');
        expect(props.durasi).toBe('30:10');
        expect(props.trimp).toBe('43');
        expect(props.subtitle).toContain('Pagi santai · ');
        expect(props.stats).toEqual({
            pace: '6:02/km',
            hr: '152 bpm',
            cadence: '178 spm',
            fastestKm: '5:12/km',
        });
        expect(props.zonePct).toEqual({ Z1: 10, Z2: 60, Z3: 30 });
        expect(props.paceShape).toEqual([360, 312]);
    });

    it('uses the words-form duration when durationFormat is words', () => {
        expect(
            kartuPropsFromDetail(fullDetail, { durationFormat: 'words' })
                .durasi,
        ).toBe('30 min 10 sec');
    });

    it('falls back to "Run" in the subtitle when the run has no name', () => {
        expect(
            kartuPropsFromDetail({ ...fullDetail, name: null }).subtitle,
        ).toContain('Run · ');
    });

    it('uses "—" sentinels and null fields when detail is null or empty', () => {
        const props = kartuPropsFromDetail(null);
        expect(props.km).toBe('—');
        expect(props.durasi).toBe('—');
        expect(props.trimp).toBe('—');
        expect(props.subtitle).toBeNull();
        expect(props.stats).toEqual({
            pace: undefined,
            hr: undefined,
            cadence: undefined,
            fastestKm: undefined,
        });
        expect(props.zonePct).toBeNull();
        expect(props.paceShape).toEqual([]);
    });
});
