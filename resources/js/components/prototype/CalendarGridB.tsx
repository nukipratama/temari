// PROTOTYPE — throwaway, calendar redesign variants
import { useState } from 'react';

import { WeekRecapPanel } from '@/components/history/CalendarWeekRow';
import { cn } from '@/lib/cn';

import type { GridProps } from './CalendarGridA';

import { hasRun, kmLabel, type ProtoCell, runCount } from './calendarProto';
import { DayTarget, EffortBar, WEEKDAYS } from './CalendarProtoParts';

const COLS = 'grid grid-cols-[3.25rem_repeat(7,minmax(0,1fr))] gap-1';

/** B: distance is the loud number; the date shrinks to a corner and the week
 *  column becomes a narrow total with a volume gauge. */
export default function CalendarGridB({
    weeks,
    snapshotsByWeek,
    onOpenDay,
}: Readonly<GridProps>) {
    const [open, setOpen] = useState<string | null>(null);
    const maxWeekKm = Math.max(1, ...weeks.map((w) => w.totalKm));

    return (
        <div>
            <div className={cn(COLS, 'mb-1.5')}>
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
                const id = `b-week-${week.weekStart}`;
                const expanded = open === week.weekStart;
                const fill = Math.round((week.totalKm / maxWeekKm) * 100);
                return (
                    <div key={week.weekStart} className="mb-1">
                        <div className={COLS}>
                            <button
                                type="button"
                                disabled={snapshot === null}
                                aria-expanded={expanded}
                                aria-controls={id}
                                aria-label={`week ${week.weekNumber}, ${kmLabel(week.totalKm)} km`}
                                onClick={() =>
                                    setOpen(expanded ? null : week.weekStart)
                                }
                                className={cn(
                                    'focus-ring pressable relative flex h-full flex-col items-end justify-between rounded-md py-1 pr-1 pl-2.5 transition-colors hover:bg-muted',
                                    expanded && 'bg-muted',
                                )}
                            >
                                <span
                                    aria-hidden
                                    className="absolute top-1 bottom-1 left-0.5 w-1 overflow-hidden rounded-full bg-muted"
                                >
                                    <span
                                        className="absolute inset-x-0 bottom-0 rounded-full bg-foreground/70"
                                        style={{ height: `${fill}%` }}
                                    />
                                </span>
                                <span className="font-mono text-[0.5625rem] leading-none whitespace-nowrap text-text-3">
                                    week {week.weekNumber}
                                </span>
                                <span className="font-mono text-sm leading-none font-bold tabular-nums text-foreground">
                                    {Math.round(week.totalKm)}
                                </span>
                            </button>
                            {week.days.map((day) => (
                                <DayTile
                                    key={day.date}
                                    cell={day as ProtoCell}
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

function DayTile({
    cell,
    onOpenDay,
}: Readonly<{ cell: ProtoCell; onOpenDay: (cell: ProtoCell) => void }>) {
    const ran = hasRun(cell);
    const n = runCount(cell);
    return (
        <DayTarget
            cell={cell}
            onOpenDay={onOpenDay}
            className={cn(
                'relative flex aspect-[4/5] min-w-0 items-center justify-center overflow-hidden rounded-t-md transition-colors',
                ran && 'bg-muted hover:bg-accent',
                !cell.is_current_month && 'opacity-35',
                cell.is_today && 'ring-2 ring-icon-accent ring-inset',
            )}
        >
            <span className="absolute top-1 left-1 font-mono text-[0.5625rem] leading-none tabular-nums text-text-3">
                {cell.day}
            </span>
            {n > 1 && (
                <span className="absolute top-1 right-1 font-mono text-[0.5625rem] leading-none font-bold text-text-2">
                    ×{n}
                </span>
            )}
            {ran && (
                <span className="font-mono text-[0.9375rem] leading-none font-bold tracking-tight tabular-nums text-foreground min-[900px]:text-xl">
                    {kmLabel(cell.distance_km ?? 0)}
                </span>
            )}
            <EffortBar
                effort={cell.effort}
                className="absolute inset-x-0 bottom-0 h-1"
            />
        </DayTarget>
    );
}
