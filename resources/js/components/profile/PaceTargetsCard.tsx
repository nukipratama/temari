import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import LegacyCard from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { formatNaiveMonthDayId, formatPace } from '@/lib/pace';
import { PR_CATEGORY_LABELS } from '@/lib/pr';

export interface TrainingPaces {
    easy: number;
    marathon: number;
    threshold: number;
    interval: number;
}

/** Which PR these targets were computed from, and when it was set. */
export interface VdotSource {
    category: string;
    /** `Y-m-d`. */
    set_at: string;
    /** No PR inside the estimator's recency window, so an older one still stands in. */
    stale: boolean;
    /** Set only when tempo and interval read a different, more recent record than
     *  easy and marathon do. Null when one record drives all four. */
    quality_category: string | null;
    quality_set_at: string | null;
}

/** One training day of the current week — `WeekSessionTypesBuilder`. */
export interface WeekSession {
    /** Lowercase short weekday, `mon` … `sun`. */
    weekday: string;
    session_type: string;
    distance_km: number;
    /** Set on the entry for the server's own today, so the accent never follows a viewer clock. */
    is_today: boolean;
}

type PaceKey = keyof TrainingPaces;

const RUNGS: {
    key: PaceKey;
    label: string;
    sessionType: string;
    fallback: string;
}[] = [
    {
        key: 'easy',
        label: 'easy',
        sessionType: 'easy',
        fallback: 'most of your runs',
    },
    {
        key: 'marathon',
        label: 'marathon',
        sessionType: 'long',
        fallback: 'long steady efforts',
    },
    {
        key: 'threshold',
        label: 'tempo',
        sessionType: 'tempo',
        fallback: 'comfortably hard, 20–40 min',
    },
    {
        key: 'interval',
        label: 'interval',
        sessionType: 'interval',
        fallback: 'short hard reps',
    },
];

/** Every bar keeps a stub, so the slowest pace still reads as a bar. */
const MIN_FILL = 12;

function prLabel(category: string): string {
    return (PR_CATEGORY_LABELS[category] ?? category).toLowerCase();
}

function distanceLabel(km: number): string {
    return km >= 1 ? ` · ${Math.round(km)}k` : '';
}

function hintFor(rung: (typeof RUNGS)[number], week: WeekSession[]): string {
    if (week.length === 0) {
        return rung.fallback;
    }

    const days = week.filter(
        (session) => session.session_type === rung.sessionType,
    );
    if (days.length === 0) {
        return 'none this week';
    }
    if (days.length === 1) {
        return `${days[0].weekday}${distanceLabel(days[0].distance_km)}`;
    }

    return days.map((session) => session.weekday).join(', ');
}

/** The pace today's session is run at, and null on a rest day or a day off-plan. */
function todaysPaceKey(week: WeekSession[]): PaceKey | null {
    const today = week.find((session) => session.is_today);
    if (today === undefined) {
        return null;
    }

    return (
        RUNGS.find((rung) => rung.sessionType === today.session_type)?.key ??
        null
    );
}

function sourceChips(source: VdotSource): string[] {
    const chips = [
        `from ${prLabel(source.category)} pr · ${formatNaiveMonthDayId(source.set_at)}`,
    ];

    if (source.stale) {
        chips.push('stale');
    }

    // Easy and marathon stay on the endurance record; tempo and interval read a
    // recent short one, which is a different number and should say so.
    if (source.quality_category !== null && source.quality_set_at !== null) {
        chips.push(
            `tempo + interval from ${prLabel(source.quality_category)} pr · ${formatNaiveMonthDayId(source.quality_set_at)}`,
        );
    }

    return chips;
}

/**
 * The four training paces as a ladder, slowest at the top. Each rung carries the
 * number to run, what the week asks of it, and a bar placing it between the
 * slowest and the fastest pace.
 */
export default function PaceTargetsCard({
    paces,
    source = null,
    weekSessions = [],
}: Readonly<{
    paces: TrainingPaces;
    source?: VdotSource | null;
    weekSessions?: WeekSession[];
}>) {
    const values = RUNGS.map((rung) => paces[rung.key]);
    const slowest = Math.max(...values);
    const span = slowest - Math.min(...values);
    const accentKey = todaysPaceKey(weekSessions);

    return (
        <LegacyCard as="section">
            <Eyebrow token="micro" tone="ink-3">
                Training · pace targets · per km
            </Eyebrow>
            <ul className="mx-4 mt-3 space-y-3.5">
                {RUNGS.map((rung) => {
                    const accented = rung.key === accentKey;
                    const fill =
                        span === 0
                            ? 50
                            : MIN_FILL +
                              ((slowest - paces[rung.key]) / span) *
                                  (100 - MIN_FILL);

                    return (
                        <li key={rung.key}>
                            <div className="flex items-baseline justify-between gap-3">
                                <span
                                    className={cn(
                                        'text-sm',
                                        accented
                                            ? 'font-semibold text-foreground'
                                            : 'text-text-2',
                                    )}
                                >
                                    {rung.label}
                                </span>
                                <b className="font-mono text-display-xs font-bold tabular-nums text-foreground">
                                    {formatPace(paces[rung.key])}
                                </b>
                            </div>
                            <p className="mt-0.5 text-xs text-text-3">
                                {hintFor(rung, weekSessions)}
                            </p>
                            <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        accented
                                            ? 'bg-icon-accent'
                                            : 'bg-border',
                                    )}
                                    style={{ width: `${fill}%` }}
                                />
                            </div>
                        </li>
                    );
                })}
            </ul>
            {source && (
                <div className="mx-4 mt-3.5 flex flex-wrap gap-1.5">
                    {sourceChips(source).map((chip) => (
                        <Chip
                            key={chip}
                            tone={chip === 'stale' ? 'horizon' : 'neutral'}
                        >
                            {chip}
                        </Chip>
                    ))}
                </div>
            )}
        </LegacyCard>
    );
}
