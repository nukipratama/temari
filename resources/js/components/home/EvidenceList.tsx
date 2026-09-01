import { Link } from '@inertiajs/react';

import type { PastYouTrend, TrendDirection } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { activityUrl } from '@/lib/routes';
import { evidenceRows } from '@/lib/verdict';

const DELTA_TONE: Record<TrendDirection, string> = {
    better: 'bg-horizon/[0.18] text-icon-accent',
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
        <ul className="mt-3 flex flex-col gap-px overflow-hidden rounded-md border border-border bg-border shadow-e1">
            {rows.map((row) => (
                <li key={row.activityId}>
                    <Link
                        href={activityUrl({ activity_id: row.activityId })}
                        aria-label={`${row.label}, ${row.then} to ${row.now}, ${row.delta}`}
                        className="focus-ring block bg-card px-3.5 py-2.5 transition-colors hover:bg-accent"
                    >
                        <span className="font-sans text-[10.5px] text-foreground">
                            {row.label}
                        </span>
                        <div className="mt-1 flex items-baseline gap-2 font-mono tabular-nums">
                            <span className="text-[12.5px] text-foreground">
                                {row.then}
                            </span>
                            <span
                                aria-hidden
                                className="text-xs text-foreground"
                            >
                                →
                            </span>
                            <span className="text-[14.5px] font-extrabold text-foreground">
                                {row.now}
                            </span>
                            <span
                                className={cn(
                                    'ml-auto rounded-full px-2 py-0.5 font-mono text-[10px] font-extrabold',
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
