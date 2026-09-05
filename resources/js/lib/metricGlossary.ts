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
        label: 'fitness',
        body: 'your average fitness over the last 42 days. the higher it is, the more ready you are for long or intense runs. it climbs slowly, and only with consistency.',
    },
    atl: {
        acronym: 'ATL',
        label: 'fatigue',
        body: "your training load over the last 7 days. high means you've just put in hard work and need some recovery before pushing again.",
    },
    form: {
        label: 'readiness',
        body: "how ready your body is to run today, from fitness minus fatigue. positive means you're fresh and ready. negative isn't necessarily bad, it means you're carrying some fatigue but still in the ideal zone for adaptation.",
    },
    trimp: {
        acronym: 'TRIMP',
        label: 'TRIMP',
        body: 'the effort score for a single run, combining duration and heart rate. the longer or harder it is, the higher the score. the line below compares this session to your average effort over the last 28 days, so you know if it was heavier or lighter than usual.',
    },
    monotony: {
        label: 'monotony',
        body: 'how much your weekly intensity varies. above 2 means your week is too uniform and injury risk goes up. slip in an easy day to bring this number down.',
    },
    strain: {
        label: 'strain',
        body: 'total stress for the week, TRIMP multiplied by monotony. high strain means load is piling up.',
    },
    decoupling: {
        label: 'decoupling',
        body: "the efficiency gap between the first and second half of a run. above 5% means HR drift, either your aerobic base isn't solid yet or you were already gassed.",
    },
    recovery: {
        label: 'break',
        body: "how long it's been since your last run, not an actual measurement of physical recovery (there's no sensor for that here). as a rough rule, around 72 hours is usually enough to be ready for another hard session, but an easy run doesn't need to wait that long.",
    },
    vibe: {
        label: 'vibe',
        body: "a summary of how you're doing today, drawn from form and your weekly trend. i use this to set the tone of your briefing.",
    },
    cadence: {
        label: 'cadence',
        body: 'steps per minute (spm). a range of 170 to 180 is common for distance runners, and it usually climbs 5 to 10 during a sprint.',
    },
    gap: {
        acronym: 'GAP',
        label: 'grade adjusted pace',
        body: 'pace recalculated as if the route were flat, so effort on hills reads honestly. a hard uphill run will come out faster than its raw pace.',
    },
    edwards_trimp: {
        acronym: 'Edwards',
        label: 'Edwards TRIMP',
        body: 'a way of calculating TRIMP that weights each HR zone. Z1 earns 1 point per minute, Z5 earns 5 points per minute. a higher score means a harder session.',
    },
    hr_zones: {
        label: 'HR zones',
        body: 'five intensity levels based on heart rate. Z1 is the easiest, Z5 is the hardest. the split between them defines what kind of session you ran.',
    },
    hr_z1: {
        acronym: 'Z1',
        label: 'zone 1: recovery',
        body: 'very easy, you could still sing while running. for recovery or cooldown.',
    },
    hr_z2: {
        acronym: 'Z2',
        label: 'zone 2: conversational',
        body: 'still easy, you can hold a conversation while running. the go-to zone for base building.',
    },
    hr_z3: {
        acronym: 'Z3',
        label: 'zone 3: tempo',
        body: "tempo pace. you're breathing hard now, only good for one or two words at a time.",
    },
    hr_z4: {
        acronym: 'Z4',
        label: 'zone 4: threshold',
        body: 'threshold pace. this is hard, only short bursts of talking. for tempo or interval sessions.',
    },
    hr_z5: {
        acronym: 'Z5',
        label: 'zone 5: max',
        body: 'sprint mode, no talking at all. used only for short intervals.',
    },
    status_fresh: {
        label: 'feeling fresh',
        body: "you're fresh and ready for a hard session. readiness is positive, fatigue is low.",
    },
    status_optimal: {
        label: 'right on track',
        body: 'right where you want to be, load and fitness are balanced. the sweet spot for consistent training.',
    },
    status_fatigued: {
        label: 'getting tired',
        body: "you're tired, ease off the intensity for now. give yourself an easy day or rest so fatigue can drop.",
    },
    status_overreaching: {
        label: 'overreaching',
        body: 'the load is way too much. rest for a few days before continuing, pushing through raises the risk of injury or illness.',
    },
    vibe_vs_mood: {
        label: 'vibe vs mood',
        body: 'vibe is your overall state today, calculated from fitness, fatigue and form. mood is the feel of a single run. vibe is one per day, mood is one per run.',
    },
    ascent: {
        label: 'ascent',
        body: 'total climb over the course of your run, in meters. the more of it there is, the harder the effort even at the same distance.',
    },
    vdot: {
        acronym: 'VDOT',
        label: 'VDOT',
        body: 'a running fitness score from your best PR, using the Jack Daniels formula. the higher it is, the more efficient your threshold pace.',
    },
    threshold_pace: {
        label: 'threshold pace',
        body: 'an estimate of your lactate-threshold pace, derived from your VDOT score. the ideal pace for a tempo run.',
    },
    pace_easy: {
        label: 'easy pace',
        body: 'an easy pace for base building, derived from your VDOT score. you can still hold a conversation at this pace.',
    },
    pace_marathon: {
        label: 'marathon pace',
        body: 'a target pace for a steady long run, between easy and threshold.',
    },
    pace_interval: {
        label: 'interval pace',
        body: 'the fastest pace, for short repeats above threshold. used for interval sessions, not long ones.',
    },
    pace_tempo: {
        label: 'tempo pace',
        body: 'a target pace for tempo sessions, derived from your VDOT score. different from the "threshold pace" card above, which is your current estimated lactate threshold from recent hard runs.',
    },
} as const satisfies Record<string, MetricGlossaryEntry>;

export type MetricKey = keyof typeof METRIC_GLOSSARY;
