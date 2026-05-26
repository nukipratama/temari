/**
 * Canonical UI verbs and microcopy for teman-lari.
 *
 * Voice rules (see CLAUDE.md and memory feedback_no_em_dash, language_convention):
 *  - Native casual Indonesian. No "translatese": prefer "lagi" over "sedang",
 *    "sambungin" over "sambungkan", "sync" over "sinkronisasi", "baca" over "analisis".
 *  - No em-dashes (—) or en-dashes (–) in copy or LLM prompts. Use comma, period,
 *    colon, or parentheses for pauses.
 *  - Running domain terms stay English (pace, split, TRIMP, threshold, etc.).
 *  - Mood vocabulary is Indonesian (nyala / enteng / oleng / lemes / mumet / adem).
 *  - Light emoji touch (1 per voice line) is welcome in mascot voice and empty
 *    states. Avoid emojis in headings, KPIs, table headers, nav labels.
 *
 * Import these constants instead of writing inline strings so the canonical
 * verb stays consistent across pages.
 */

/** Canonical CTA verbs. Pick the one whose semantics fits the action. */
export const CTA = {
    /** Open detail / drill into a subject. Replaces "Lihat detail". */
    buka: 'Buka',
    /** "See all" affordance, paired with a → arrow. */
    semua: 'Semua',
    /** Connect external service (Strava). Casual native form. */
    sambungin: 'Sambungin',
    /** Disconnect external service. */
    putus: 'Putus',
    /** Equip accessory. */
    pasang: 'Pasang',
    /** Already-equipped state label (disabled button). */
    lagiDipake: 'Lagi dipake',
    /** Re-run LLM narration. Replaces "Analisis ulang". */
    bacaUlang: 'Baca ulang',
    /** Trigger first LLM narration. Replaces "Analisis sekarang". */
    mintaTemariBacain: 'Minta Temari bacain',
    /** Acknowledge tooltip / onboarding. Replaces "Baik, ditunggu". */
    sipDitunggu: 'Sip, ditunggu',
    /** Acknowledge / start. */
    sipMulai: 'Sip, mulai',
    /** Retry after failure. */
    cobaLagi: 'Coba lagi',
    /** Cancel / back out of a flow. */
    batal: 'Batal',
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
