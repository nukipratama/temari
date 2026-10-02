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
        label: 'long-term load',
        body: "your running load averaged over about six weeks (42 days). it counts running only and shows how much you've been running, not how fit you are.",
    },
    atl: {
        acronym: 'ATL',
        label: 'short-term load',
        body: 'your running load over about the last week (7 days). it counts running only. a high number means a big recent week, not a measure of how tired you are.',
    },
    form: {
        label: 'load balance',
        body: "long-term load minus short-term load. above zero reads fresh, near zero steady, well below zero heavy. it compares running load only; it can't tell how ready your body is.",
    },
    trimp: {
        acronym: 'TRIMP',
        label: 'TRIMP',
        body: 'the effort score for a run, combining duration and heart rate. the longer or harder it is, the higher the score.',
    },
    monotony: {
        label: 'monotony',
        body: "how alike each day's load was this week. above 2, every day looked the same; an easy or rest day breaks it up.",
    },
    strain: {
        label: 'strain',
        body: "the week's TRIMP multiplied by monotony. a high number means a big, uniform week of running load.",
    },
    decoupling: {
        label: 'decoupling',
        body: 'how much your heart rate drifted against pace across the steadiest stretch of a run. above 12% is a lot of drift. heat, a long duration and not drinking enough all raise it, so read it next to the conditions.',
    },
    recovery: {
        label: 'break',
        body: "time since your last run, not a measurement of recovery. one demanding session in the last 24 hours, or more than one in the last 48 hours, holds back full-dose quality. an easy run doesn't need to wait.",
    },
    vibe: {
        label: 'vibe',
        body: "a summary of how you're doing today, drawn from load balance and your weekly trend. i use this to set the tone of your briefing.",
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
        label: 'fresh',
        body: 'your recent running load is lighter than your longer-term load. it counts running only.',
    },
    status_steady: {
        label: 'steady',
        body: 'your recent running load is close to your longer-term load. it counts running only.',
    },
    status_heavy: {
        label: 'heavy',
        body: 'your recent running load is above your longer-term load, which is normal in a build week. if you feel run down, illness, poor sleep or under-fuelling can be the cause too, so tell temari how you feel.',
    },
    vibe_vs_mood: {
        label: 'vibe vs mood',
        body: "vibe is your overall state today, calculated from your load numbers and the week's trend. mood is the feel of a single run. vibe is one per day, mood is one per run.",
    },
    ascent: {
        label: 'ascent',
        body: 'total climb over the course of your run, in meters. the more of it there is, the harder the effort even at the same distance.',
    },
    vdot: {
        acronym: 'VDOT',
        label: 'VDOT',
        body: 'a running score from your most conservative recent result, using the Jack Daniels formula. your training paces come from it.',
    },
    threshold_pace: {
        label: 'threshold pace',
        body: 'comfortably hard, about the pace you could race for one hour. derived from your VDOT.',
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
        body: 'a target pace for tempo sessions, derived from your VDOT score. comfortably hard, about one-hour race effort. different from the "threshold pace" card above, which is estimated from your recent hard runs.',
    },
} as const satisfies Record<string, MetricGlossaryEntry>;

export type MetricKey = keyof typeof METRIC_GLOSSARY;
