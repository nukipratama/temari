/**
 * Canonical UI verbs and microcopy for temari.
 *
 * Voice rules (see CLAUDE.md and memory feedback_no_em_dash):
 *  - Casual global running-app register (Strava/Nike-Run-Club-adjacent): neutral,
 *    warm, contractions fine, plain words over formal ones.
 *  - No em-dashes (—) or en-dashes (–) in copy or LLM prompts. Use comma, period,
 *    colon, or parentheses for pauses.
 *  - Running domain terms stay English (pace, split, TRIMP, threshold, etc.).
 *  - Mood values are still keyed by their Indonesian slugs (nyala / enteng /
 *    oleng / lemes / mumet / adem) pending the DB key migration in a later slice.
 *  - Light emoji touch (1 per voice line) is welcome in mascot voice and empty
 *    states. Avoid emojis in headings, KPIs, table headers, nav labels.
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

/** Mood-keyed emoji palette (D5). One emoji per voice line, never on chips. */
export const MOOD_EMOJI = {
    nyala: '🔥',
    enteng: '🌸',
    oleng: '⚡',
    lemes: '💧',
    mumet: '🌀',
    adem: '🍃',
} as const;
