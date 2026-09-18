import type { CardFactsPayload } from '@/lib/card/types';

/**
 * A run's card facts as the server ships them, matching what `CardFacts::from()`
 * resolves for a mid-table easy run. Overrides push it to any other form.
 */
export function makeCardFacts(
    overrides: Partial<CardFactsPayload> = {},
): CardFactsPayload {
    return {
        form: 'easy',
        rarity: 'common',
        kind: 'EASY RUN',
        km: '5.28',
        distance_km: 5.284,
        time: '32:18',
        pace: '6:07',
        heart_rate: '142',
        elevation: '18',
        place: 'Senayan, Jakarta Pusat, Indonesia',
        place_short: 'SENAYAN',
        weather: '29°C · wind 8 km/h',
        date_long: 'SUN 13 SEP 2026',
        date_short: '13.09.26',
        clock: '05:41',
        badges: ['Heat Tamer'],
        serial: 'TMR-0418',
        polyline: '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
        race_name: null,
        race_distance: null,
        splits: [],
        pace_profile: [],
        ...overrides,
    };
}
