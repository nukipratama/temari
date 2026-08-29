import { motion } from 'framer-motion';

import StatTile from '@/components/ui/StatTile';
import { cn } from '@/lib/cn';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatDurationHMS, formatNaiveIdDate, formatPace } from '@/lib/pace';

export interface DistanceRecord {
    category: string;
    label: string;
    distanceM: number;
    valueSec: number;
    setAt: string;
}

export interface PaceRecord {
    category: string;
    label: string;
    paceSec: number;
    setAt: string;
}

interface PersonalBestsProps {
    distanceRecords: ReadonlyArray<DistanceRecord>;
    paceRecords: ReadonlyArray<PaceRecord>;
    className?: string;
}

/**
 * Personal Bests panel — every distance PR and best-effort-by-time PR the
 * user has set, sourced straight from PersonalRecord (no history, just the
 * current best per category). Always full history, not range-scoped — this
 * sits alongside the range-aware panels above it on the page.
 */
export default function PersonalBests({
    distanceRecords,
    paceRecords,
    className,
}: Readonly<PersonalBestsProps>) {
    const hasAny = distanceRecords.length > 0 || paceRecords.length > 0;

    return (
        <div
            className={cn(
                'flex flex-col gap-6 rounded-(--radius-panel) border border-border bg-card p-6 shadow-(--shadow-panel) sm:p-8',
                className,
            )}
        >
            <div>
                <p className="text-label-micro text-text-3">Records</p>
                <h2 className="mt-1 font-serif text-lg text-foreground">
                    Personal Bests
                </h2>
                <p className="mt-1 text-sm text-text-2">
                    Every distance you have a best for, and the fastest pace you
                    have held for a given stretch of time. Temari logs these off
                    your synced runs, you never enter one by hand.
                </p>
            </div>

            {!hasAny && (
                <p className="text-sm text-text-3">
                    Run to set your first personal best — it shows up here
                    automatically.
                </p>
            )}

            {distanceRecords.length > 0 && (
                <div className="flex flex-col gap-3">
                    <div className="flex items-baseline justify-between">
                        <h3 className="text-sm font-semibold text-foreground">
                            By distance
                        </h3>
                        <span className="text-xs text-text-3">
                            {distanceRecords.length} PRs
                        </span>
                    </div>
                    <motion.div
                        initial="hidden"
                        animate="visible"
                        variants={staggerContainer}
                        className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-3"
                    >
                        {distanceRecords.map((record) => (
                            <motion.div
                                key={record.category}
                                variants={fadeInUp}
                            >
                                <StatTile
                                    tone="sunken"
                                    size="sm"
                                    label={record.label}
                                    value={formatDurationHMS(record.valueSec)}
                                    sub={`${formatPace(record.valueSec / (record.distanceM / 1000))}/km · ${formatNaiveIdDate(record.setAt, 'short')}`}
                                />
                            </motion.div>
                        ))}
                    </motion.div>
                </div>
            )}

            {paceRecords.length > 0 && (
                <div className="flex flex-col gap-3">
                    <div className="flex items-baseline justify-between">
                        <h3 className="text-sm font-semibold text-foreground">
                            Best effort by time
                        </h3>
                        <span className="text-xs text-text-3">
                            {paceRecords.length} PRs
                        </span>
                    </div>
                    <ul className="divide-y divide-line rounded-lg bg-muted">
                        {paceRecords.map((record) => (
                            <li
                                key={record.category}
                                className="flex items-baseline justify-between gap-3 px-3 py-2.5"
                            >
                                <span className="text-sm text-foreground">
                                    {record.label}
                                </span>
                                <span className="flex items-baseline gap-3">
                                    <span className="font-sans text-sm font-bold tabular-nums text-foreground">
                                        {formatPace(record.paceSec)}/km
                                    </span>
                                    <span className="text-xs text-text-3">
                                        {formatNaiveIdDate(
                                            record.setAt,
                                            'short',
                                        )}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
