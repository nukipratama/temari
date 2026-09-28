// PROTOTYPE — throwaway, calendar redesign variants
import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { type ReactNode } from 'react';

import type { Effort } from '@/types/inertia';

import { Icon } from '@/components/ui/Icon';
import Sheet from '@/components/ui/Sheet';
import { cn } from '@/lib/cn';
import { EFFORT_ICON_CLASS } from '@/lib/effort';
import { MOOD_LABEL } from '@/lib/mood';
import { formatNaiveMonthDayId, formatPace } from '@/lib/pace';
import { activityUrl } from '@/lib/routes';

import {
    type Consistency,
    describeDay,
    EFFORT_FILL,
    EFFORT_WORD,
    kmLabel,
    type ProtoCell,
    runCount,
} from './calendarProto';

export const WEEKDAYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'] as const;

/** Single run links to its detail, several open the day sheet, none is inert. */
export function DayTarget({
    cell,
    onOpenDay,
    className,
    children,
}: Readonly<{
    cell: ProtoCell;
    onOpenDay: (cell: ProtoCell) => void;
    className?: string;
    children: ReactNode;
}>) {
    const n = runCount(cell);
    const label = describeDay(cell);
    const id = cell.activity_id ?? cell.runs[0]?.activity_id ?? null;

    if (n === 1 && id !== null) {
        return (
            <Link
                href={activityUrl({ activity_id: id })}
                aria-label={label}
                className={cn('pressable focus-ring', className)}
            >
                {children}
            </Link>
        );
    }
    if (n > 1) {
        return (
            <button
                type="button"
                aria-label={label}
                aria-haspopup="dialog"
                onClick={() => onOpenDay(cell)}
                className={cn('pressable focus-ring', className)}
            >
                {children}
            </button>
        );
    }
    return (
        <div aria-label={label} className={className}>
            {children}
        </div>
    );
}

export function EffortBar({
    effort,
    className,
}: Readonly<{ effort: Effort | null; className?: string }>) {
    if (effort === null) {
        return null;
    }
    return (
        <span
            aria-hidden
            className={cn(
                effort === 'rest' ? 'h-0' : 'h-[3px]',
                EFFORT_FILL[effort],
                className,
            )}
        />
    );
}

export function EffortLegend({ className }: Readonly<{ className?: string }>) {
    const efforts: Effort[] = ['easy', 'steady', 'hard', 'unknown', 'rest'];
    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-2',
                className,
            )}
        >
            <span className="text-label-micro text-text-3">effort</span>
            {efforts.map((effort) => (
                <span
                    key={effort}
                    className="flex items-center gap-1.5 text-xs text-text-2"
                >
                    <EffortBar effort={effort} className="block w-4" />
                    {EFFORT_WORD[effort]}
                </span>
            ))}
        </div>
    );
}

export function ConsistencyLine({
    stats,
    className,
}: Readonly<{ stats: Consistency; className?: string }>) {
    return (
        <p
            className={cn(
                'text-center font-mono text-xs tabular-nums text-text-2',
                className,
            )}
        >
            <span className="whitespace-nowrap">
                {stats.runs} run{stats.runs === 1 ? '' : 's'} ·{' '}
                {Math.round(stats.km)} km ·
            </span>{' '}
            <span className="whitespace-nowrap">
                ran {stats.ranDays}/{stats.daysInMonth} days · longest streak{' '}
                {stats.longestStreak}
            </span>
        </p>
    );
}

export function DayRunsSheet({
    cell,
    onClose,
}: Readonly<{ cell: ProtoCell | null; onClose: () => void }>) {
    return (
        <Sheet
            open={cell !== null}
            onOpenChange={(open) => !open && onClose()}
            title={
                cell
                    ? `${formatNaiveMonthDayId(cell.date)} · ${runCount(cell)} runs`
                    : ''
            }
        >
            {cell && (
                <>
                    <p className="mt-1 font-mono text-xs text-text-3">
                        {kmLabel(cell.distance_km ?? 0)} km total
                        {cell.faked && ' · second run faked for the prototype'}
                    </p>
                    <ul className="mt-3 divide-y divide-dashed divide-border">
                        {cell.runs.map((run) => (
                            <li key={run.activity_id}>
                                <Link
                                    href={activityUrl(run)}
                                    className="pressable focus-ring -mx-2 flex items-center gap-3 rounded-md px-2 py-3 hover:bg-muted"
                                >
                                    <EffortBar
                                        effort={run.effort}
                                        className="block h-9 w-[3px] flex-none"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-semibold text-foreground">
                                            {run.name ?? 'run'}
                                        </span>
                                        <span className="mt-0.5 block font-mono text-xs tabular-nums text-text-2">
                                            {kmLabel(run.distance_km ?? 0)} km
                                            {run.pace_sec_per_km !== null &&
                                                ` · ${formatPace(run.pace_sec_per_km)}/km`}
                                        </span>
                                    </span>
                                    <span className="flex flex-none flex-col items-end gap-0.5 text-xs">
                                        <span
                                            className={cn(
                                                'font-semibold',
                                                EFFORT_ICON_CLASS[run.effort],
                                            )}
                                        >
                                            {EFFORT_WORD[run.effort]}
                                        </span>
                                        {run.mood && (
                                            <span className="text-text-3">
                                                felt {MOOD_LABEL[run.mood]}
                                            </span>
                                        )}
                                    </span>
                                    <Icon
                                        icon={ChevronRight}
                                        width={16}
                                        height={16}
                                        className="flex-none text-text-3"
                                        aria-hidden
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Sheet>
    );
}
