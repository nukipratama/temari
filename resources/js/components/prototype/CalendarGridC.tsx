// PROTOTYPE — throwaway, calendar redesign variants
import { useState } from 'react';

import { WeekRecapPanel } from '@/components/history/CalendarWeekRow';
import { cn } from '@/lib/cn';

import type { GridProps } from './CalendarGridA';

import {
    EFFORT_FILL,
    hasRun,
    kmLabel,
    type ProtoCell,
    runCount,
} from './calendarProto';
import { DayTarget, WEEKDAYS } from './CalendarProtoParts';

const COLS = 'grid grid-cols-[3.25rem_repeat(7,minmax(0,1fr))] gap-x-1';

/** C: every week is a small bar chart on a dashed baseline — bar height is the
 *  day's distance, bar colour its effort, the km printed on top, the date under. */
export default function CalendarGridC({
    weeks,
    snapshotsByWeek,
    onOpenDay,
}: Readonly<GridProps>) {
    const [open, setOpen] = useState<string | null>(null);
    const maxKm = Math.max(
        1,
        ...weeks.flatMap((w) => w.days.map((d) => d.distance_km ?? 0)),
    );

    return (
        <div>
            <div className={cn(COLS, 'mb-1')}>
                <span aria-hidden />
                {WEEKDAYS.map((d) => (
                    <span
                        key={d}
                        className="text-center text-label-micro text-text-3"
                    >
                        {d}
                    </span>
                ))}
            </div>
            {weeks.map((week) => {
                const snapshot = snapshotsByWeek.get(week.weekEnding) ?? null;
                const id = `c-week-${week.weekStart}`;
                const expanded = open === week.weekStart;
                const runs = week.days.reduce(
                    (sum, day) => sum + runCount(day as ProtoCell),
                    0,
                );
                return (
                    <div
                        key={week.weekStart}
                        className="border-b border-dashed border-border py-1.5 first:pt-0"
                    >
                        <div className={COLS}>
                            <button
                                type="button"
                                disabled={snapshot === null}
                                aria-expanded={expanded}
                                aria-controls={id}
                                onClick={() =>
                                    setOpen(expanded ? null : week.weekStart)
                                }
                                className={cn(
                                    'focus-ring pressable flex h-full flex-col justify-end gap-0.5 rounded-md px-1.5 pt-1 pb-1.5 text-left transition-colors hover:bg-muted',
                                    expanded && 'bg-muted',
                                )}
                            >
                                <span className="font-mono text-[0.625rem] leading-none text-text-3">
                                    week {week.weekNumber}
                                </span>
                                <span className="font-mono text-sm leading-none font-bold tabular-nums text-foreground">
                                    {kmLabel(week.totalKm)}
                                </span>
                                <span className="font-mono text-[0.5625rem] leading-none text-text-3">
                                    {runs} run
                                    {runs === 1 ? '' : 's'}
                                </span>
                            </button>
                            {week.days.map((day) => (
                                <BarDay
                                    key={day.date}
                                    cell={day as ProtoCell}
                                    maxKm={maxKm}
                                    onOpenDay={onOpenDay}
                                />
                            ))}
                        </div>
                        {expanded && snapshot !== null && (
                            <WeekRecapPanel
                                id={id}
                                week={week}
                                snapshot={snapshot}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function BarDay({
    cell,
    maxKm,
    onOpenDay,
}: Readonly<{
    cell: ProtoCell;
    maxKm: number;
    onOpenDay: (cell: ProtoCell) => void;
}>) {
    const ran = hasRun(cell);
    const n = runCount(cell);
    const height = ran
        ? Math.max(8, Math.round(((cell.distance_km ?? 0) / maxKm) * 100))
        : 0;

    return (
        <DayTarget
            cell={cell}
            onOpenDay={onOpenDay}
            className={cn(
                'flex min-w-0 flex-col items-center rounded-md transition-colors',
                ran && 'hover:bg-muted',
                !cell.is_current_month && 'opacity-35',
            )}
        >
            <span className="flex h-14 w-full flex-col items-center justify-end">
                {ran && (
                    <span className="mb-0.5 font-mono text-[0.625rem] leading-none font-bold tabular-nums whitespace-nowrap text-foreground">
                        {kmLabel(cell.distance_km ?? 0)}
                        {n > 1 && (
                            <span className="font-normal text-text-3">
                                ×{n}
                            </span>
                        )}
                    </span>
                )}
                {ran && cell.effort !== null && (
                    <span
                        aria-hidden
                        className={cn(
                            'w-3/5 max-w-6 rounded-t-[3px]',
                            EFFORT_FILL[cell.effort],
                        )}
                        style={{ height: `${height * 0.7}%` }}
                    />
                )}
                {!ran && cell.effort === 'rest' && (
                    <span
                        aria-hidden
                        className="h-2 w-3/5 max-w-6 rounded-t-[3px] border-2 border-b-0 border-dashed border-border-strong"
                    />
                )}
            </span>
            <span className="h-px w-full bg-border-strong" aria-hidden />
            <span
                className={cn(
                    'mt-0.5 flex size-5 flex-col items-center justify-center rounded-full font-mono text-[0.625rem] leading-none tabular-nums',
                    ran ? 'font-bold text-foreground' : 'text-text-3',
                    cell.is_today && 'ring-[1.5px] ring-icon-accent',
                )}
            >
                {cell.day}
            </span>
        </DayTarget>
    );
}
