import { describe, expect, it } from 'vitest';
import { BADGE_ABILITY, BADGE_LABELS, RARITY_LABELS, RARITY_ORDER, badgeEmblem, badgeName } from './runcard';

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
    it('exposes the Indonesian rarity ladder labels', () => {
        expect(RARITY_LABELS).toEqual({
            common: 'Biasa',
            uncommon: 'Berkesan',
            rare: 'Langka',
            epic: 'Luar Biasa',
            legendary: 'Legendaris',
        });
    });
});

const BADGE_KEYS = ['hari_panas', 'pejuang_hujan', 'anak_pagi', 'long_slow_distance', 'negative_split', 'tahan_diri'];

describe('BADGE_LABELS', () => {
    it('has expected badge keys', () => {
        BADGE_KEYS.forEach((key) => {
            expect(BADGE_LABELS[key]).toBeTruthy();
        });
    });

    // The two English names are running terms (code-switch rule); the rest are ID-first.
    it('uses the ID-first casual names', () => {
        expect(BADGE_LABELS.hari_panas).toBe('🔥 Tahan Gerah');
        expect(BADGE_LABELS.tahan_diri).toBe('🧘 Anti Kalap');
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
        expect(badgeEmblem('hari_panas')).toBe('🔥');
        expect(badgeName('hari_panas')).toBe('Tahan Gerah');
        expect(badgeName('tahan_diri')).toBe('Anti Kalap');
    });

    it('falls back to prettyBadge for unknown slugs', () => {
        expect(badgeEmblem('unknown_slug')).toBe('');
        expect(badgeName('unknown_slug')).toBe('Unknown Slug');
    });
});
