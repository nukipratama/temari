/**
 * Fixture data for the Trends prototype. Nothing here talks to an API.
 *
 * The series are generated rather than hand-typed so they stay internally
 * consistent: a single invented daily-TRIMP history is the only input, and
 * fitness, fatigue, strain and monotony are then derived from it with the same
 * formulas the app already uses (app/Services/Run/Metrics/TrainingLoad.php).
 * VDOT is derived with the real Daniels maths from
 * app/Services/Run/Metrics/VdotEstimator.php. That keeps the shapes plausible
 * by construction instead of by taste.
 */

const ANCHOR = Date.UTC(2026, 7, 15); // 2026-08-15, the last day in the window
const DAYS = 365;

function mulberry32(seed: number) {
    let a = seed;
    return () => {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const rand = mulberry32(20260815);

function isoAt(dayIndex: number): string {
    const d = new Date(ANCHOR - (DAYS - 1 - dayIndex) * 86_400_000);
    return d.toISOString().slice(0, 10);
}

// ---------------------------------------------------------------------------
// The one invented input: a year of daily Edwards TRIMP.
// ---------------------------------------------------------------------------

/** Mon rest, Tue quality, Wed/Thu easy, Fri rest, Sat long, Sun recovery. */
const WEEK_SHAPE = [0, 92, 54, 62, 0, 138, 46];

/** Days 118-131: two weeks lost to illness, logged as no runs at all. */
const ILLNESS = { from: 118, to: 131 };
/**
 * Days 245-272: a four-week run-every-day streak. Losing both rest days is what
 * pushes monotony past its threshold, which no ordinary week here does.
 */
const STREAK = { from: 245, to: 272 };
const STREAK_SHAPE = [45, 95, 55, 60, 40, 130, 50];
/** Day 300: a half marathon, preceded by a taper and followed by a down week. */
const RACE_DAY = 300;

function seasonalScale(day: number): number {
    const week = Math.floor(day / 7);
    // A slow year-long build, on a 3-up-1-down cycle.
    const build = 0.72 + 0.32 * (day / DAYS);
    const cycle = week % 4 === 3 ? 0.68 : 1;
    if (day > RACE_DAY - 14 && day <= RACE_DAY) return build * 0.6;
    if (day > RACE_DAY && day <= RACE_DAY + 10) return build * 0.45;
    return build * cycle;
}

export interface DailyLoad {
    date: string;
    trimp: number | null;
    ran: boolean;
}

export const dailyLoad: ReadonlyArray<DailyLoad> = Array.from(
    { length: DAYS },
    (_, day) => {
        const date = isoAt(day);
        if (day >= ILLNESS.from && day <= ILLNESS.to) {
            return { date, trimp: 0, ran: false };
        }
        const streak = day >= STREAK.from && day <= STREAK.to;
        const base = (streak ? STREAK_SHAPE : WEEK_SHAPE)[(day + 2) % 7];
        if (base === 0) return { date, trimp: 0, ran: false };
        if (day === RACE_DAY) return { date, trimp: 212, ran: true };
        const jitter = 0.85 + rand() * 0.3;
        return {
            date,
            trimp: Math.round(base * seasonalScale(day) * jitter),
            ran: true,
        };
    },
);

// ---------------------------------------------------------------------------
// Fitness & fatigue: the app's EWMA, taus 42 and 7.
// ---------------------------------------------------------------------------

const CTL_TAU = 42;
const ATL_TAU = 7;

export interface LoadPoint {
    date: string;
    ctl: number;
    atl: number;
    form: number;
}

export const fitnessTrend: ReadonlyArray<LoadPoint> = (() => {
    let ctl = 34;
    let atl = 36;
    return dailyLoad.map(({ date, trimp }) => {
        const t = trimp ?? 0;
        ctl += (t - ctl) / CTL_TAU;
        atl += (t - atl) / ATL_TAU;
        return {
            date,
            ctl: Math.round(ctl * 10) / 10,
            atl: Math.round(atl * 10) / 10,
            form: Math.round((ctl - atl) * 10) / 10,
        };
    });
})();

// ---------------------------------------------------------------------------
// Strain & monotony: TrainingLoad::weekStats(), rolled forward one week at a
// time instead of only over the trailing 7 days.
// ---------------------------------------------------------------------------

export interface WeekLoad {
    weekEnding: string;
    weekly: number | null;
    monotony: number | null;
    strain: number | null;
}

function weekStats(
    week: ReadonlyArray<DailyLoad>,
): Omit<WeekLoad, 'weekEnding'> {
    const ranAtAll = week.some((d) => d.ran);
    const scored = week.filter((d) => d.trimp !== null);
    if (scored.length === 0) {
        return ranAtAll
            ? { weekly: null, monotony: null, strain: null }
            : { weekly: 0, monotony: 0, strain: 0 };
    }
    const values = week.map((d) => d.trimp ?? 0);
    const weekly = values.reduce((a, b) => a + b, 0);
    if (weekly <= 0) return { weekly: 0, monotony: 0, strain: 0 };
    const mean = weekly / 7;
    const variance = values.reduce((a, t) => a + (t - mean) ** 2, 0) / 7;
    const sd = Math.sqrt(variance);
    const monotony =
        sd > 0.01 ? Math.min(5, Math.round((mean / sd) * 100) / 100) : 5;
    return {
        weekly,
        monotony,
        strain: Math.round(weekly * monotony * 10) / 10,
    };
}

export const weeklyLoad: ReadonlyArray<WeekLoad> = (() => {
    const out: WeekLoad[] = [];
    for (let end = DAYS - 1; end >= 6; end -= 7) {
        const week = dailyLoad.slice(end - 6, end + 1);
        out.unshift({ weekEnding: dailyLoad[end].date, ...weekStats(week) });
    }
    return out;
})();

// ---------------------------------------------------------------------------
// VDOT, with the real Daniels maths from VdotEstimator.
// ---------------------------------------------------------------------------

export function vdotFor(distanceM: number, timeSec: number): number {
    const timeMin = timeSec / 60;
    const v = distanceM / timeMin;
    const vo2 = -4.6 + 0.182258 * v + 0.000104 * v * v;
    const pmax =
        0.8 +
        0.1894393 * Math.exp(-0.012778 * timeMin) +
        0.2989558 * Math.exp(-0.1932605 * timeMin);
    return Math.round((vo2 / pmax) * 10) / 10;
}

// ---------------------------------------------------------------------------
// Pace consistency: the spread of per-km split paces, in seconds.
// Bands come from PaceConsistency.php: <=8 steady, <=15 fairly steady,
// <=20 a bit up and down, >20 up and down.
// ---------------------------------------------------------------------------

export const CONSISTENCY_BANDS = [
    { max: 8, label: 'Steady', tone: 'good' as const },
    { max: 15, label: 'Fairly steady', tone: 'good' as const },
    { max: 20, label: 'A bit up and down', tone: 'watch' as const },
    { max: 40, label: 'Up and down', tone: 'high' as const },
];

export interface ConsistencyPoint {
    weekEnding: string;
    variabilitySec: number | null;
}

export const consistencyTrend: ReadonlyArray<ConsistencyPoint> = weeklyLoad.map(
    (w, i) => {
        if (w.weekly === null || w.weekly === 0) {
            return { weekEnding: w.weekEnding, variabilitySec: null };
        }
        const progress = i / (weeklyLoad.length - 1);
        const drift = 19.5 - 9 * progress;
        const rustAfterIllness = i > 17 && i < 23 ? 4.2 : 0;
        return {
            weekEnding: w.weekEnding,
            variabilitySec:
                Math.round(
                    (drift + rustAfterIllness + (rand() - 0.5) * 3.4) * 10,
                ) / 10,
        };
    },
);

// ---------------------------------------------------------------------------
// Personal records. Fields mirror resources/js/pages/Collection/Records.tsx.
// ---------------------------------------------------------------------------

export interface DistanceRecord {
    category: string;
    label: string;
    distanceM: number;
    valueSec: number;
    setAt: string;
    runName: string;
    previousSec: number | null;
}

export const distanceRecords: ReadonlyArray<DistanceRecord> = [
    {
        category: '1km',
        label: '1K',
        distanceM: 1000,
        valueSec: 232,
        setAt: isoAt(322),
        runName: 'Track reps, finally clean',
        previousSec: 241,
    },
    {
        category: '5km',
        label: '5K',
        distanceM: 5000,
        valueSec: 1294,
        setAt: isoAt(268),
        runName: 'Parkrun, cool morning',
        previousSec: 1331,
    },
    {
        category: '10km',
        label: '10K',
        distanceM: 10000,
        valueSec: 2712,
        setAt: isoAt(230),
        runName: 'Sunday tempo out and back',
        previousSec: 2795,
    },
    {
        category: '15km',
        label: '15K',
        distanceM: 15000,
        valueSec: 4248,
        setAt: isoAt(300),
        runName: 'Half marathon, first 15K split',
        previousSec: 4390,
    },
    {
        category: 'half_marathon',
        label: 'Half',
        distanceM: 21097.5,
        valueSec: 6086,
        setAt: isoAt(300),
        runName: 'Race day',
        previousSec: 6402,
    },
    {
        category: 'marathon',
        label: 'Full',
        distanceM: 42195,
        valueSec: 12760,
        setAt: isoAt(41),
        runName: 'The big one, last October',
        previousSec: null,
    },
];

export interface PaceRecord {
    category: string;
    label: string;
    paceSec: number;
    setAt: string;
}

export const paceRecords: ReadonlyArray<PaceRecord> = [
    {
        category: 'best_5min',
        label: 'Best 5 min',
        paceSec: 245,
        setAt: isoAt(322),
    },
    {
        category: 'best_10min',
        label: 'Best 10 min',
        paceSec: 258,
        setAt: isoAt(268),
    },
    {
        category: 'best_20min',
        label: 'Best 20 min',
        paceSec: 273,
        setAt: isoAt(268),
    },
    {
        category: 'best_30min',
        label: 'Best 30 min',
        paceSec: 281,
        setAt: isoAt(230),
    },
    {
        category: 'best_60min',
        label: 'Best 60 min',
        paceSec: 298,
        setAt: isoAt(300),
    },
];

// ---------------------------------------------------------------------------
// Per-distance progression: month-by-month best time at each distance.
// ---------------------------------------------------------------------------

export interface ProgressionPoint {
    date: string;
    timeSec: number | null;
}

export interface DistanceProgression {
    category: string;
    label: string;
    goalSec: number | null;
    points: ReadonlyArray<ProgressionPoint>;
}

function progressionFor(
    record: DistanceRecord,
    startFactor: number,
    goalSec: number | null,
    gaps: ReadonlyArray<number>,
): DistanceProgression {
    const months = 12;
    const start = record.valueSec * startFactor;
    const points: ProgressionPoint[] = [];
    for (let m = 0; m < months; m++) {
        const day = Math.min(
            DAYS - 1,
            Math.round((m / (months - 1)) * (DAYS - 1)),
        );
        if (gaps.includes(m)) {
            points.push({ date: isoAt(day), timeSec: null });
            continue;
        }
        const progress = m / (months - 1);
        // Improvement flattens out, and the illness block costs a little back.
        const curve = 1 - (1 - 1 / startFactor) * Math.sqrt(progress);
        const setback = m >= 4 && m <= 5 ? 0.012 : 0;
        const noise = (rand() - 0.5) * 0.012;
        points.push({
            date: isoAt(day),
            timeSec: Math.round(start * (curve + setback + noise)),
        });
    }
    // The PR is pinned onto the month it was actually set in, keeping that month's
    // own date so the axis stays in order, and every other month is held strictly
    // slower so the PR really is the minimum of the series.
    const target = Date.parse(record.setAt);
    let pin = -1;
    points.forEach((p, i) => {
        if (p.timeSec === null) return;
        if (
            pin < 0 ||
            Math.abs(Date.parse(p.date) - target) <
                Math.abs(Date.parse(points[pin].date) - target)
        ) {
            pin = i;
        }
    });
    points.forEach((p, i) => {
        if (p.timeSec === null) return;
        if (i === pin) p.timeSec = record.valueSec;
        else if (p.timeSec <= record.valueSec) {
            p.timeSec = record.valueSec + 4 + Math.round(rand() * 22);
        }
    });
    return { category: record.category, label: record.label, goalSec, points };
}

export const progressions: ReadonlyArray<DistanceProgression> = [
    progressionFor(distanceRecords[0], 1.07, 225, [1, 6]),
    progressionFor(distanceRecords[1], 1.09, 1260, []),
    progressionFor(distanceRecords[2], 1.1, 2640, [4]),
    progressionFor(distanceRecords[3], 1.08, null, [0, 1, 2, 4, 5, 7, 9]),
    progressionFor(distanceRecords[4], 1.11, 5940, [1, 2, 4, 5, 7, 8]),
    progressionFor(
        distanceRecords[5],
        1.04,
        null,
        [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
    ),
];

// ---------------------------------------------------------------------------
// VDOT history, run off the progression series rather than invented separately,
// so the headline number and the chart cannot disagree.
// ---------------------------------------------------------------------------

/** The estimator keeps the slowest of the per-PR VDOTs, so the weakest distance sets it. */
export const vdotByRecord = distanceRecords.map((r) => ({
    label: r.label,
    vdot: vdotFor(r.distanceM, r.valueSec),
}));

export const currentVdot = Math.min(...vdotByRecord.map((r) => r.vdot));
export const vdotLimiter = vdotByRecord.find(
    (r) => r.vdot === currentVdot,
)!.label;

interface Effort {
    at: number;
    distanceM: number;
    timeSec: number;
}

const efforts: ReadonlyArray<Effort> = progressions.flatMap((p) => {
    const distanceM = distanceRecords.find(
        (r) => r.category === p.category,
    )!.distanceM;
    return p.points
        .filter((pt) => pt.timeSec !== null)
        .map((pt) => ({
            at: Date.parse(pt.date),
            distanceM,
            timeSec: pt.timeSec!,
        }));
});

/** VdotEstimator over one slice of history: best time per distance, then the slowest score. */
function estimate(window: ReadonlyArray<Effort>): number | null {
    const bestPerDistance = new Map<number, number>();
    for (const e of window) {
        const best = bestPerDistance.get(e.distanceM);
        if (best === undefined || e.timeSec < best)
            bestPerDistance.set(e.distanceM, e.timeSec);
    }
    if (bestPerDistance.size === 0) return null;
    return Math.min(
        ...[...bestPerDistance].map(([distanceM, timeSec]) =>
            vdotFor(distanceM, timeSec),
        ),
    );
}

const ROLLING_WINDOW_MS = 90 * 86_400_000;

export interface VdotPoint {
    weekEnding: string;
    /** Today's estimator replayed over history: all-time PRs, so it can only climb. */
    fromRecords: number | null;
    /** The same estimator over a rolling 90 days, so a lost block actually shows as a fall. */
    rolling90: number | null;
}

export const vdotTrend: ReadonlyArray<VdotPoint> = weeklyLoad.map((w) => {
    const at = Date.parse(w.weekEnding);
    return {
        weekEnding: w.weekEnding,
        fromRecords: estimate(efforts.filter((e) => e.at <= at)),
        rolling90: estimate(
            efforts.filter((e) => e.at <= at && e.at > at - ROLLING_WINDOW_MS),
        ),
    };
});

// ---------------------------------------------------------------------------
// Badges, positioned as milestones on the fitness timeline.
// Names and criteria copy are the real ones from resources/js/lib/runcard.ts.
// ---------------------------------------------------------------------------

export interface Milestone {
    key: string;
    emoji: string;
    name: string;
    criterion: string;
    date: string;
    /** The one-line reason this badge landed on this day. */
    note: string;
}

export const milestones: ReadonlyArray<Milestone> = [
    {
        key: 'early_bird',
        emoji: '🌅',
        name: 'Early Bird',
        criterion: 'Out the door before 6am.',
        date: isoAt(22),
        note: 'First 5am alarm of the block.',
    },
    {
        key: 'long_slow_distance',
        emoji: '🐢',
        name: 'Long Slow Distance',
        criterion: 'Long and easy, 12K+ at a mostly relaxed pace.',
        date: isoAt(58),
        note: '14K and you never touched threshold.',
    },
    {
        key: 'climber',
        emoji: '⛰️',
        name: 'Climber',
        criterion: '200m+ of elevation gain, basically a mountain.',
        date: isoAt(96),
        note: '244m in one run, your most so far.',
    },
    {
        key: 'rain_warrior',
        emoji: '🌧️',
        name: 'Rain Warrior',
        criterion: 'Kept running through the rain.',
        date: isoAt(112),
        note: 'Soaked at kilometre 2, kept going to 11.',
    },
    {
        key: 'negative_split',
        emoji: '👻',
        name: 'Negative Split',
        criterion: 'Second half faster than the first.',
        date: isoAt(148),
        note: 'First run back after two weeks off, and still even.',
    },
    {
        key: 'held_back',
        emoji: '🧘',
        name: 'Held Back',
        criterion: '10K+ and stayed patient instead of chasing pace.',
        date: isoAt(186),
        note: '12K entirely in Z2, no surges.',
    },
    {
        key: 'z2_master',
        emoji: '🫀',
        name: 'Z2 Master',
        criterion: 'Almost the whole run in Z2.',
        date: isoAt(214),
        note: '91% of the run in Z2.',
    },
    {
        key: 'speedster',
        emoji: '⚡',
        name: 'Speedster',
        criterion: 'Pace under 5:00/km, fast.',
        date: isoAt(268),
        note: '5K at 4:19/km, a PR on the same run.',
    },
    {
        key: 'heat_tamer',
        emoji: '🔥',
        name: 'Heat Tamer',
        criterion: 'Braved a run in 31°C+ heat.',
        date: isoAt(284),
        note: '32°C at 3pm, which was a choice.',
    },
    {
        key: 'all_out',
        emoji: '😤',
        name: 'All Out',
        criterion: 'Emptied the tank, max effort.',
        date: isoAt(300),
        note: 'Race day. Half marathon PR by 5 minutes.',
    },
    {
        key: 'long_hauler',
        emoji: '🗺️',
        name: 'Long Hauler',
        criterion: 'A genuinely long one, 21K+.',
        date: isoAt(300),
        note: 'Same run, 21.1K.',
    },
];

// ---------------------------------------------------------------------------
// Window filtering, shared by every chart on the page.
// ---------------------------------------------------------------------------

export const RANGES = [
    { key: '30d', label: '30 days', days: 30 },
    { key: '90d', label: '90 days', days: 90 },
    { key: '12mo', label: '12 months', days: DAYS },
] as const;

export type RangeKey = (typeof RANGES)[number]['key'];

export function daysFor(range: RangeKey): number {
    return RANGES.find((r) => r.key === range)!.days;
}

export function withinRange<T extends { date?: string; weekEnding?: string }>(
    rows: ReadonlyArray<T>,
    range: RangeKey,
): ReadonlyArray<T> {
    const cutoff = ANCHOR - (daysFor(range) - 1) * 86_400_000;
    return rows.filter((r) => Date.parse((r.date ?? r.weekEnding)!) >= cutoff);
}
