import { Link } from '@inertiajs/react';
import { Sparkle } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import Chip from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
import { activityUrl } from '@/lib/routes';
import { RARITY_INK, RARITY_LABELS } from '@/lib/runcard';
import {
    barSegments,
    describeDay,
    EFFORT_FILL,
    gridMaxKm,
    hasRun,
    kmLabel,
    runCount,
} from '@/pages/Activities/calendarBars';
import {
    type CalendarCell,
    type WeekRow,
} from '@/pages/Activities/useCalendar';

import WeeklyStatLine from './WeeklyStatLine';

const WEEKDAYS = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'] as const;
const COLS = 'grid grid-cols-[3.25rem_repeat(7,minmax(0,1fr))] gap-x-1';

/**
 * The calendar grid: every week is a small bar chart on a dashed baseline —
 * bar height is the day's distance, bar colour its effort, the km printed on
 * top, the date under. Replaces the old bordered day boxes + mood dot.
 */
export default function CalendarGrid({
    weeks,
    snapshotsByWeek,
    onOpenDay,
}: Readonly<{
    weeks: ReadonlyArray<WeekRow>;
    snapshotsByWeek: Map<string, WeeklySnapshotWithRecap>;
    onOpenDay: (cell: CalendarCell) => void;
}>) {
    const [openWeek, setOpenWeek] = useState<string | null>(null);
    const maxKm = gridMaxKm(weeks.flatMap((week) => week.days));

    return (
        <div>
            <div className={cn(COLS, 'mb-1')}>
                <span aria-hidden />
                {WEEKDAYS.map((label) => (
                    <span
                        key={label}
                        className="text-center text-label-micro text-text-3"
                    >
                        {label}
                    </span>
                ))}
            </div>
            {weeks.map((week) => {
                const snapshot = snapshotsByWeek.get(week.weekEnding) ?? null;
                const disclosureId = `week-${week.weekStart}-recap`;
                const expanded = openWeek === week.weekStart;
                const runs = week.days.reduce(
                    (sum, day) => sum + runCount(day),
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
                                aria-controls={disclosureId}
                                onClick={() =>
                                    setOpenWeek(
                                        expanded ? null : week.weekStart,
                                    )
                                }
                                className={cn(
                                    'focus-ring pressable flex h-full flex-col justify-end gap-0.5 rounded-md px-1 pt-1 pb-1.5 text-left transition-colors hover:bg-muted',
                                    expanded && 'bg-muted',
                                )}
                            >
                                <span className="text-meta leading-none whitespace-nowrap">
                                    week {week.weekNumber}
                                </span>
                                <span className="font-mono text-sm leading-none font-bold tabular-nums text-foreground">
                                    {kmLabel(week.totalKm)}
                                </span>
                                <span className="text-meta leading-none whitespace-nowrap">
                                    {runs} run{runs === 1 ? '' : 's'}
                                </span>
                            </button>
                            {week.days.map((day) => (
                                <DayBar
                                    key={day.date}
                                    cell={day}
                                    maxKm={maxKm}
                                    onOpenDay={onOpenDay}
                                />
                            ))}
                        </div>
                        {expanded && snapshot !== null && (
                            <WeekRecapPanel
                                id={disclosureId}
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

function DayBar({
    cell,
    maxKm,
    onOpenDay,
}: Readonly<{
    cell: CalendarCell;
    maxKm: number;
    onOpenDay: (cell: CalendarCell) => void;
}>) {
    const ran = hasRun(cell);
    const segments = ran ? barSegments(cell, maxKm) : [];

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
                    <span className="mb-0.5 text-meta leading-none font-bold tracking-normal tabular-nums whitespace-nowrap text-foreground">
                        {kmLabel(cell.distance_km ?? 0)}
                    </span>
                )}
                {ran && segments.length > 0 && (
                    <span
                        className="flex w-3/5 max-w-6 flex-col-reverse gap-[2px]"
                        style={{ height: '70%' }}
                    >
                        {segments.map((segment) => (
                            <span
                                key={segment.activityId}
                                aria-hidden
                                className={cn(
                                    'w-full last:rounded-t-[3px]',
                                    EFFORT_FILL[segment.effort],
                                )}
                                style={{ height: `${segment.heightPct}%` }}
                            />
                        ))}
                    </span>
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
                    'mt-0.5 flex size-5 flex-col items-center justify-center rounded-full text-meta leading-none tabular-nums',
                    ran ? 'font-bold text-foreground' : 'text-text-3',
                    cell.is_today && 'ring-[1.5px] ring-icon-accent',
                )}
            >
                {cell.day}
            </span>
        </DayTarget>
    );
}

/** A day with one run links to its detail, 2+ opens the sheet, empty is inert. */
function DayTarget({
    cell,
    onOpenDay,
    className,
    children,
}: Readonly<{
    cell: CalendarCell;
    onOpenDay: (cell: CalendarCell) => void;
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

function WeekRecapPanel({
    id,
    week,
    snapshot,
}: Readonly<{
    id: string;
    week: WeekRow;
    snapshot: WeeklySnapshotWithRecap;
}>) {
    return (
        <div id={id} className="mt-1.5 mb-1 rounded-sm bg-muted px-3 py-2.5">
            <AnalysisStatus
                analysis={snapshot.recap_analysis}
                thinkingMark
                inertiaReloadProps={['weeklySnapshots']}
                awaitingSchedule={snapshot.is_current_week}
                chained
                isChainHead={snapshot.is_chain_head}
                size="sm"
                renderContent={(content) => (
                    <p className="narration-dense m-0">
                        &quot;{renderBold(stripEdgeQuotes(content))}&quot;
                    </p>
                )}
            />
            <div className="mt-1.75 flex flex-wrap items-start gap-1.5">
                <WeeklyStatLine snapshot={snapshot} />
                {week.rarity && (
                    <Chip className={RARITY_INK[week.rarity]}>
                        <Icon
                            icon={Sparkle}
                            width={10}
                            height={10}
                            aria-hidden
                        />
                        {RARITY_LABELS[week.rarity]} card
                    </Chip>
                )}
            </div>
        </div>
    );
}
