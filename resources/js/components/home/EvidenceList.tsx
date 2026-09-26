import { Link } from '@inertiajs/react';

import type { PastYouTrend, TrendDirection } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { EFFORT_STRIPE_CLASS } from '@/lib/effort';
import { activityUrl } from '@/lib/routes';
import {
    type EvidenceReading,
    type EvidenceRow,
    evidenceRows,
} from '@/lib/verdict';

const DELTA_TONE: Record<TrendDirection, string> = {
    better: 'bg-horizon/[0.18] text-icon-accent',
    flat: 'bg-muted text-text-3',
    worse: 'bg-ember/[0.15] text-ember-ink',
};

/**
 * The matched pairs behind the verdict, one row each: what made them
 * comparable, then average pace and average heart rate before and after, so a
 * row decided on efficiency shows both readings it came from.
 */
export default function EvidenceList({
    trend,
}: Readonly<{ trend: PastYouTrend }>) {
    const rows = evidenceRows(trend);

    if (rows.length === 0) {
        return null;
    }

    return (
        <ul className="mt-3 flex flex-col divide-y divide-border">
            {rows.map((row) => (
                <li key={row.activityId}>
                    <Link
                        href={activityUrl({ activity_id: row.activityId })}
                        aria-label={ariaLabel(row)}
                        className={cn(
                            'focus-ring block py-2.5 pl-2.5 transition-colors hover:bg-muted',
                            EFFORT_STRIPE_CLASS[row.effort ?? 'unknown'],
                        )}
                    >
                        <span className="font-sans text-[0.65625rem] text-foreground">
                            {row.label}
                        </span>
                        <div className="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1 font-mono tabular-nums">
                            <Reading reading={row.pace} unit="/km" />
                            {row.hr !== null && (
                                <Reading reading={row.hr} unit="bpm" />
                            )}
                            <span
                                className={cn(
                                    'ml-auto rounded-full px-2 py-0.5 font-mono text-[0.625rem] font-extrabold',
                                    DELTA_TONE[row.direction],
                                )}
                            >
                                {row.delta}
                            </span>
                        </div>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function ariaLabel(row: EvidenceRow): string {
    const hr =
        row.hr === null ? '' : `, HR ${row.hr.then} to ${row.hr.now} bpm`;
    return `${row.label}, pace ${row.pace.then} to ${row.pace.now}${hr}, ${row.delta}`;
}

function Reading({
    reading,
    unit,
}: Readonly<{ reading: EvidenceReading; unit: string }>) {
    return (
        <span className="flex items-baseline gap-1.5">
            <span className="text-[0.78125rem] text-foreground">
                {reading.then}
            </span>
            <span aria-hidden className="text-xs text-foreground">
                →
            </span>
            <span className="text-[0.90625rem] font-extrabold text-foreground">
                {reading.now}
            </span>
            <span className="text-[0.625rem] text-text-3">{unit}</span>
        </span>
    );
}
