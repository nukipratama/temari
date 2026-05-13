import { Link } from '@inertiajs/react';
import { cn } from '@/lib/cn';
import { formatIdDate, formatPace } from '@/lib/pace';
import { MOOD_FACE, MASCOT_GRADIENT, moodRing } from '@/lib/mood';
import type { ActivityDetail, Mood } from '@/types/inertia';

interface RunListRowProps {
    detail: ActivityDetail;
    /** Optional mood derived from this run's StoryLine — drives the small mood face on the left. */
    mood?: Mood | null;
}

/**
 * One row in the runs index / dashboard recent list.
 * Adds per-row mood face on the left so users feel the vibe before the numbers.
 */
export default function RunListRow({ detail, mood = null }: Readonly<RunListRowProps>) {
    const km = detail.distance != null ? (detail.distance / 1000).toFixed(2) : '—';
    const paceSec =
        detail.moving_time != null && detail.distance != null && detail.distance > 0
            ? detail.moving_time / (detail.distance / 1000)
            : null;
    const paceLabel = paceSec != null ? formatPace(paceSec) : '—';
    const hr = detail.average_heartrate != null ? Math.round(detail.average_heartrate) : null;
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;
    const safeMood: Mood = mood ?? 'dim';

    return (
        <Link
            href={`/runs/${detail.activity_id}`}
            className="flex items-center gap-4 border-b border-line px-5 py-4 text-sm transition last:border-b-0 hover:bg-surface dark:border-line-dark dark:hover:bg-surface-dark-elev"
        >
            <span
                className={cn(
                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm ring-2',
                    MASCOT_GRADIENT,
                    moodRing(safeMood),
                )}
                aria-label={`mood ${safeMood}`}
            >
                {MOOD_FACE[safeMood]}
            </span>
            <div className="min-w-0 flex-1">
                <div className="truncate font-medium text-ink dark:text-ink-dark">{detail.name ?? 'Run'}</div>
                <div className="text-xs text-ink-soft dark:text-ink-soft-dark">{formatIdDate(detail.start_date_local)}</div>
            </div>
            <div className="flex items-center gap-5 tabular-nums">
                <Cell value={km} unit="km" emphasize />
                <Cell value={paceLabel} unit="/km" hideOnNarrow="sm" />
                <Cell value={hr ?? '—'} unit="bpm" hideOnNarrow="md" tone="alert" />
                <Cell value={trimp ?? '—'} unit="TRIMP" hideOnNarrow="md" />
            </div>
        </Link>
    );
}

function Cell({
    value,
    unit,
    emphasize = false,
    hideOnNarrow,
    tone,
}: Readonly<{
    value: string | number;
    unit: string;
    emphasize?: boolean;
    hideOnNarrow?: 'sm' | 'md';
    tone?: 'alert';
}>) {
    const hideClass = hideOnNarrow === 'sm' ? 'hidden sm:block' : hideOnNarrow === 'md' ? 'hidden md:block' : '';
    const toneClass = tone === 'alert' ? 'text-mood-cooked' : '';
    return (
        <div className={cn('text-center', hideClass)}>
            <div className={cn(emphasize ? 'font-bold text-ink dark:text-ink-dark' : '', toneClass)}>{value}</div>
            <div className="text-[10px] uppercase tracking-wide text-ink-soft dark:text-ink-soft-dark">{unit}</div>
        </div>
    );
}
