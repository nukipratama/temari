/**
 * Beginner-friendly explanations for every sport-science term surfaced
 * across the app. Each entry is a 1-2 sentence explanation keyed by a
 * stable slug. Components opt in via `<MetricExplainer metricKey="ctl" />`
 * next to the label they want to demystify.
 *
 * Voice matches the Temari persona: casual, warm, contractions fine,
 * common running terms stay English, obscure ones get explained, no
 * em-dash, no markdown. See docs/voice-and-tone.md.
 */

export interface MetricGlossaryEntry {
    /** The shorthand label/acronym the user actually sees on the surface (e.g., "CTL", "Z2"). */
    acronym?: string;
    /** Human-readable name. Used as the popover heading. */
    label: string;
    /** 1-2 sentence explanation. Plain prose, no markdown. */
    body: string;
}

export const METRIC_GLOSSARY = {
    ctl: {
        acronym: 'CTL',
        label: 'Fitness',
        body: 'Your average fitness over the last 42 days. The higher it is, the more ready you are for long or intense runs. It climbs slowly, and only with consistency.',
    },
    atl: {
        acronym: 'ATL',
        label: 'Fatigue',
        body: "Your training load over the last 7 days. High means you've just put in hard work and need some recovery before pushing again.",
    },
    form: {
        label: 'Readiness',
        body: "How ready your body is to run today, from Fitness minus Fatigue. Positive means you're fresh and ready. Negative isn't necessarily bad, it means you're carrying some fatigue but still in the ideal zone for adaptation.",
    },
    trimp: {
        acronym: 'TRIMP',
        label: 'TRIMP',
        body: 'The effort score for a single run, combining duration and heart rate. The longer or harder it is, the higher the score. The line below compares this session to your average effort over the last 28 days, so you know if it was heavier or lighter than usual.',
    },
    monotony: {
        label: 'Monotony',
        body: 'How much your weekly intensity varies. Above 2 means your week is too uniform and injury risk goes up. Slip in an easy day to bring this number down.',
    },
    strain: {
        label: 'Strain',
        body: 'Total stress for the week, TRIMP multiplied by Monotony. High strain means load is piling up.',
    },
    decoupling: {
        label: 'Decoupling',
        body: "The efficiency gap between the first and second half of a run. Above 5% means HR drift, either your aerobic base isn't solid yet or you were already gassed.",
    },
    recovery: {
        label: 'Break',
        body: "How long it's been since your last run, not an actual measurement of physical recovery (there's no sensor for that here). As a rough rule, around 72 hours is usually enough to be ready for another hard session, but an easy run doesn't need to wait that long.",
    },
    vibe: {
        label: 'Vibe',
        body: "A summary of how you're doing today, drawn from Form and your weekly trend. I use this to set the tone of your briefing.",
    },
    cadence: {
        label: 'Cadence',
        body: 'Steps per minute (spm). A range of 170 to 180 is common for distance runners, and it usually climbs 5 to 10 during a sprint.',
    },
    gap: {
        acronym: 'GAP',
        label: 'Grade Adjusted Pace',
        body: 'Pace recalculated as if the route were flat, so effort on hills reads honestly. A hard uphill run will come out faster than its raw pace.',
    },
    edwards_trimp: {
        acronym: 'Edwards',
        label: 'Edwards TRIMP',
        body: 'A way of calculating TRIMP that weights each HR zone. Z1 earns 1 point per minute, Z5 earns 5 points per minute. A higher score means a harder session.',
    },
    hr_zones: {
        label: 'HR Zones',
        body: 'Five intensity levels based on heart rate. Z1 is the easiest, Z5 is the hardest. The split between them defines what kind of session you ran.',
    },
    hr_z1: {
        acronym: 'Z1',
        label: 'Zone 1: Recovery',
        body: 'Very easy, you could still sing while running. For recovery or cooldown.',
    },
    hr_z2: {
        acronym: 'Z2',
        label: 'Zone 2: Conversational',
        body: 'Still easy, you can hold a conversation while running. The go-to zone for base building.',
    },
    hr_z3: {
        acronym: 'Z3',
        label: 'Zone 3: Tempo',
        body: "Tempo pace. You're breathing hard now, only good for one or two words at a time.",
    },
    hr_z4: {
        acronym: 'Z4',
        label: 'Zone 4: Threshold',
        body: 'Threshold pace. This is hard, only short bursts of talking. For tempo or interval sessions.',
    },
    hr_z5: {
        acronym: 'Z5',
        label: 'Zone 5: Max',
        body: 'Sprint mode, no talking at all. Used only for short intervals.',
    },
    status_fresh: {
        label: 'Feeling Fresh',
        body: "You're fresh and ready for a hard session. Readiness is positive, fatigue is low.",
    },
    status_optimal: {
        label: 'Right on Track',
        body: 'Right where you want to be, load and fitness are balanced. The sweet spot for consistent training.',
    },
    status_fatigued: {
        label: 'Getting Tired',
        body: "You're tired, ease off the intensity for now. Give yourself an easy day or rest so fatigue can drop.",
    },
    status_overreaching: {
        label: 'Overreaching',
        body: 'The load is way too much. Rest for a few days before continuing, pushing through raises the risk of injury or illness.',
    },
    vibe_vs_mood: {
        label: 'Vibe vs Mood',
        body: 'Vibe is your overall state today, calculated from fitness, fatigue and form. Mood is the feel of a single run. Vibe is one per day, mood is one per run.',
    },
    ascent: {
        label: 'Ascent',
        body: 'Total climb over the course of your run, in meters. The more of it there is, the harder the effort even at the same distance.',
    },
    vdot: {
        acronym: 'VDOT',
        label: 'VDOT',
        body: 'A running fitness score from your best PR, using the Jack Daniels formula. The higher it is, the more efficient your threshold pace.',
    },
    threshold_pace: {
        label: 'Threshold pace',
        body: 'An estimate of your lactate-threshold pace, derived from your VDOT score. The ideal pace for a tempo run.',
    },
    pace_easy: {
        label: 'Easy pace',
        body: 'An easy pace for base building, derived from your VDOT score. You can still hold a conversation at this pace.',
    },
    pace_marathon: {
        label: 'Marathon pace',
        body: 'A target pace for a steady long run, between easy and threshold.',
    },
    pace_interval: {
        label: 'Interval pace',
        body: 'The fastest pace, for short repeats above threshold. Used for interval sessions, not long ones.',
    },
    pace_tempo: {
        label: 'Tempo pace',
        body: 'A target pace for tempo sessions, derived from your VDOT score. Different from the "Threshold pace" card above, which is your current estimated lactate threshold from recent hard runs.',
    },
} as const satisfies Record<string, MetricGlossaryEntry>;

export type MetricKey = keyof typeof METRIC_GLOSSARY;
