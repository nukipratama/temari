import type { Rarity } from '@/types/inertia';

/** Which composition the run earns, resolved server-side in `CardFacts`. */
export type RunForm = 'easy' | 'long' | 'race' | 'pr' | 'nogps';

export const CARD_STYLES = ['broadsheet', 'ticket', 'topo'] as const;
export type CardStyle = (typeof CARD_STYLES)[number];

export const CARD_ASPECTS = ['story', 'feed'] as const;
export type CardAspect = (typeof CARD_ASPECTS)[number];

/** Both export shapes are 1080 wide; only the height changes. */
export const CARD_WIDTH = 1080;

/**
 * The story app's reserved bands. Every style holds its content inside this
 * window; the strip above and below is the card's own ground.
 */
export const SAFE_TOP = 270;
export const SAFE_BOTTOM = 1650;

export function cardHeight(aspect: CardAspect): number {
    return aspect === 'story' ? 1920 : 1080;
}

/** A label + value pair a ruled row prints. */
export type Cell = [string, string];

/** The card payload the run page ships, straight off `CardFacts::toArray()`. */
export interface CardFactsPayload {
    form: RunForm;
    rarity: Rarity;
    kind: string;
    km: string;
    distance_km: number;
    time: string;
    pace: string;
    heart_rate: string | null;
    elevation: string | null;
    place: string;
    place_short: string;
    weather: string | null;
    date_long: string;
    date_short: string;
    clock: string;
    badges: string[];
    serial: string;
    polyline: string | null;
    race_name: string | null;
    race_distance: string | null;
    splits: Cell[];
    pace_profile: number[];
}

/**
 * The optional facts the athlete toggles in the share popup. Everything else —
 * distance, time, pace, route, date, place and the wordmark — is on every card
 * and has no switch.
 */
export interface CardOptions {
    hr: boolean;
    elevation: boolean;
    weather: boolean;
    badges: boolean;
}

export const ALL_FACTS: CardOptions = {
    hr: true,
    elevation: true,
    weather: true,
    badges: true,
};
