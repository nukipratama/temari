import { Link } from '@inertiajs/react';

import type { PastYouTrend, TrendDirection } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { activityUrl } from '@/lib/routes';
import { evidenceRows } from '@/lib/verdict';

const DELTA_TONE: Record<TrendDirection, string> = {
    better: 'bg-leaf/[0.15] text-leaf-ink',
    flat: 'bg-muted text-text-3',
    worse: 'bg-ember/[0.15] text-ember-ink',
};

/**
 * The matched pairs behind the verdict, one row each: what made them
 * comparable, then the reading that decided the row's direction, before and
 * after. Rows whose pace came back inside the noise band show heart rate
 * instead, because that is the number that actually made the call.
 */
export default function EvidenceList({
    trend,
}: Readonly<{ trend: PastYouTrend }>) {
    const rows = evidenceRows(trend);

    if (rows.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col gap-px overflow-hidden rounded-md bg-line shadow-e1">
            {rows.map((row) => (
                <li key={row.activityId}>
                    <Link
                        href={activityUrl({ activity_id: row.activityId })}
                        aria-label={`${row.label}, ${row.then} to ${row.now}, ${row.delta}`}
                        className="focus-ring block bg-card pad-panel transition-colors hover:bg-accent"
                    >
                        <span className="font-sans text-xs text-text-3">
                            {row.label}
                        </span>
                        <div className="mt-1.5 flex items-baseline gap-2 font-mono tabular-nums">
                            <span className="text-[15px] text-text-3">
                                {row.then}
                            </span>
                            <span aria-hidden className="text-xs text-text-3">
                                →
                            </span>
                            <span className="text-lg font-bold text-foreground">
                                {row.now}
                            </span>
                            <span
                                className={cn(
                                    'pad-chip ml-auto rounded-full font-mono text-[11px] font-bold',
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
