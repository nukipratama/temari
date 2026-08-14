/**
 * Canonical UI verbs and microcopy for temari.
 *
 * Voice is defined in docs/voice-and-tone.md, not here. The two rules that bite
 * most often when writing UI chrome: chrome stays Title Case (the lowercase
 * tendency belongs to Temari's narrated voice only), and no em-dashes.
 *
 * Import these constants instead of writing inline strings so the canonical
 * verb stays consistent across pages.
 */

/** Canonical CTA verbs. Pick the one whose semantics fits the action. */
export const CTA = {
    /** Open detail / drill into a subject. */
    buka: 'Open',
    /** "See all" affordance, paired with a → arrow. */
    semua: 'See all',
    /** Connect external service (Strava). */
    sambungin: 'Connect',
    /** Disconnect external service. */
    putus: 'Disconnect',
    /** Equip accessory. */
    pasang: 'Equip',
    /** Already-equipped state label (disabled button). */
    lagiDipake: 'Equipped',
    /** Re-run LLM narration. */
    bacaUlang: 'Reread',
    /** Trigger first LLM narration. */
    mintaTemariBacain: 'Ask Temari to read it',
    /** Acknowledge / start. */
    sipMulai: "Let's go",
    /** Retry after failure. */
    cobaLagi: 'Try again',
    /** Cancel / back out of a flow. */
    batal: 'Cancel',
} as const;

/** Mood-keyed emoji palette. Currently unwired; see the emoji rule in docs/voice-and-tone.md before using it. */
export const MOOD_EMOJI = {
    blazing: '🔥',
    easy: '🌸',
    wobbly: '⚡',
    gassed: '💧',
    overloaded: '🌀',
    chill: '🍃',
} as const;
