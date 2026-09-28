// PROTOTYPE — throwaway, calendar redesign variants
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

import type { WeekRow } from '@/pages/Activities/useCalendar';
import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import { WeekRecapPanel } from '@/components/history/CalendarWeekRow';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';

import { hasRun, kmLabel, type ProtoCell, runCount } from './calendarProto';
import { DayTarget, EffortBar, WEEKDAYS } from './CalendarProtoParts';

export interface GridProps {
    weeks: WeekRow[];
    snapshotsByWeek: Map<string, WeeklySnapshotWithRecap>;
    onOpenDay: (cell: ProtoCell) => void;
}

const COLS = 'grid grid-cols-[3.5rem_repeat(7,minmax(0,1fr))] gap-1';

/** A: Plan's borderless tile language — date over distance, effort bar under. */
export default function CalendarGridA({
    weeks,
    snapshotsByWeek,
    onOpenDay,
}: Readonly<GridProps>) {
    const [open, setOpen] = useState<string | null>(null);

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
                const id = `a-week-${week.weekStart}`;
                const expanded = open === week.weekStart;
                return (
                    <div key={week.weekStart} className="mb-1">
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
                                    'focus-ring pressable flex h-full flex-col items-start justify-center gap-0.5 rounded-lg px-1.5 py-1.5 text-left transition-colors hover:bg-muted',
                                    expanded && 'bg-muted',
                                )}
                            >
                                <span className="font-mono text-[0.625rem] font-bold text-text-3">
                                    week {week.weekNumber}
                                </span>
                                <span className="flex items-center gap-0.5 font-mono text-xs font-bold tabular-nums text-foreground">
                                    {week.runCount > 0
                                        ? kmLabel(week.totalKm)
                                        : '·'}
                                    {snapshot !== null && (
                                        <Icon
                                            icon={ChevronDown}
                                            width={10}
                                            height={10}
                                            className={cn(
                                                'text-text-3 transition-transform',
                                                expanded && 'rotate-180',
                                            )}
                                            aria-hidden
                                        />
                                    )}
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
                'relative flex min-h-12 min-w-0 flex-col items-center gap-0.5 rounded-t-lg pt-1.5 pb-2 transition-colors',
                ran && 'hover:bg-muted',
                !cell.is_current_month && 'opacity-35',
                cell.is_today && 'ring-[1.5px] ring-icon-accent ring-inset',
            )}
        >
            <span
                className={cn(
                    'font-mono text-[0.6875rem] tabular-nums',
                    ran ? 'font-bold text-foreground' : 'text-text-3',
                )}
            >
                {cell.day}
            </span>
            {ran && (
                <span className="font-mono text-[0.6875rem] font-bold tabular-nums whitespace-nowrap text-text-2">
                    {kmLabel(cell.distance_km ?? 0)}
                </span>
            )}
            {n > 1 && (
                <span className="absolute top-1 right-1 font-mono text-[0.5rem] font-bold text-text-3">
                    ×{n}
                </span>
            )}
            <EffortBar
                effort={cell.effort}
                className="absolute inset-x-0 bottom-0"
            />
        </DayTarget>
    );
}
