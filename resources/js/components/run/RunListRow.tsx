import { cn } from '@/lib/cn';
import { formatIdDate, formatKm, formatPace, paceSecPerKm } from '@/lib/pace';
import MotionLink from '@/components/MotionLink';
import { Icon } from '@iconify/react';
import { pressShrink } from '@/lib/motion';
import { moodFromActivity } from '@/lib/moodFromActivity';
import TemariMascot from '@/components/temari/TemariMascot';
import type { ActivityDetail, Mood } from '@/types/inertia';

export interface RunNote {
    oneline: string;
    mood: Mood;
}

interface RunListRowProps {
    detail: ActivityDetail;
    mood?: Mood | null;
    note?: RunNote | null;
}

export default function RunListRow({ detail, mood = null, note = null }: Readonly<RunListRowProps>) {
    const km = formatKm(detail.distance);
    const paceSec = paceSecPerKm(detail.moving_time, detail.distance);
    const paceLabel = paceSec != null ? formatPace(paceSec) : '—';
    const hr = detail.average_heartrate != null ? Math.round(detail.average_heartrate) : null;
    const trimp = detail.trimp_edwards != null ? Math.round(detail.trimp_edwards) : null;
    const safeMood: Mood = note?.mood ?? mood ?? moodFromActivity(detail);

    return (
        <MotionLink
            href={`/aktivitas/${detail.activity_id}`}
            whileTap={pressShrink}
            className="flex items-start gap-4 border-b border-line px-5 py-4 text-sm transition last:border-b-0 hover:bg-surface"
        >
            <TemariMascot
                mood={safeMood}
                sizeClass="h-16 w-16 shrink-0"
                aria-label={`mood ${safeMood}`}
            />
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <div className="flex items-center gap-4">
                    <div className="min-w-0 flex-1">
                        <div className="truncate font-medium text-ink">{detail.name ?? 'Run'}</div>
                        <div className="text-xs text-ink-3">{formatIdDate(detail.start_date_local)}</div>
                    </div>
                    <div className="flex items-center gap-5 tabular-nums">
                        <Cell value={km} unit="km" emphasize />
                        <Cell value={paceLabel} unit="/km" hideOnNarrow="sm" />
                        <Cell value={hr ?? '—'} unit="bpm" hideOnNarrow="md" tone="alert" />
                        <Cell value={trimp ?? '—'} unit="TRIMP" hideOnNarrow="md" />
                    </div>
                </div>
                {note && (
                    <div className="flex items-start gap-2 rounded-xl bg-surface-warm/60 px-3 py-2 text-xs leading-relaxed text-ink">
                        <Icon
                            icon="mdi:comment-quote-outline"
                            width={14}
                            height={14}
                            aria-hidden
                            className="mt-0.5 shrink-0 text-leaf-deep"
                        />
                        <p className="min-w-0">{note.oneline}</p>
                    </div>
                )}
            </div>
        </MotionLink>
    );
}

interface CellProps {
    value: string | number;
    unit: string;
    emphasize?: boolean;
    hideOnNarrow?: 'sm' | 'md';
    tone?: 'alert';
}

const HIDE_CLASSES = {
    sm: 'hidden sm:block',
    md: 'hidden md:block',
} as const;

function Cell({ value, unit, emphasize = false, hideOnNarrow, tone }: Readonly<CellProps>) {
    return (
        <div className={cn('text-center', hideOnNarrow && HIDE_CLASSES[hideOnNarrow])}>
            <div
                className={cn(
                    emphasize && 'font-bold text-ink',
                    tone === 'alert' && 'text-mood-lemes',
                )}
            >
                {value}
            </div>
            <div className="text-[10px] uppercase tracking-wide text-ink-3">{unit}</div>
        </div>
    );
}
