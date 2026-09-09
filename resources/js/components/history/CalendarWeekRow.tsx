import { Link } from '@inertiajs/react';
import { useState } from 'react';

import type { WeeklySnapshotWithRecap } from '@/types/inertia';

import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { MOOD_FILL, MOOD_LABEL } from '@/lib/mood';
import { formatPace } from '@/lib/pace';
import { renderBold, stripEdgeQuotes } from '@/lib/richText';
import { activityUrl } from '@/lib/routes';
import { RARITY_INK, RARITY_LABELS } from '@/lib/runcard';
import {
    type CalendarCell,
    type WeekRow,
} from '@/pages/Activities/useCalendar';

import WeeklyStatusChips from './WeeklyStatusChips';

const CELL_BASE =
    'flex min-h-8 flex-col items-center justify-center gap-0.5 rounded-xs border border-border-strong bg-card py-1.5 font-mono text-[0.59375rem] leading-[1.2] font-bold text-foreground';

/**
 * One Mon-Sun row of the calendar: a week-summary button on the left, seven day
 * boxes beside it, and — when the week carries a recap — an expandable panel
 * with Temari's narration, the weekly chips and the week's rarest card (P12).
 */
export default function CalendarWeekRow({
    week,
    snapshot,
}: Readonly<{ week: WeekRow; snapshot: WeeklySnapshotWithRecap | null }>) {
    const [expanded, setExpanded] = useState(false);
    const disclosureId = `week-${week.weekStart}-recap`;

    return (
        <div className="mb-1">
            <div className="grid grid-cols-[30px_repeat(7,minmax(0,1fr))] gap-0.75">
                <WeekSummaryButton
                    week={week}
                    expanded={expanded}
                    disclosureId={disclosureId}
                    disabled={snapshot === null}
                    onToggle={() => setExpanded((open) => !open)}
                />
                {week.days.map((day) => (
                    <DayBox key={day.date} cell={day} />
                ))}
            </div>
            {expanded && snapshot !== null && (
                <div
                    id={disclosureId}
                    className="mt-1 mb-2 rounded-sm bg-muted px-3 py-2.5"
                >
                    <AnalysisStatus
                        analysis={snapshot.recap_analysis}
                        inertiaReloadProps={['weeklySnapshots']}
                        awaitingSchedule={snapshot.is_current_week}
                        chained
                        isChainHead={snapshot.is_chain_head}
                        size="sm"
                        renderContent={(content) => (
                            <p className="narration-dense m-0">
                                &quot;{renderBold(stripEdgeQuotes(content))}
                                &quot;
                            </p>
                        )}
                    />
                    <div className="mt-1.75 flex flex-wrap gap-1.5">
                        <WeeklyStatusChips snapshot={snapshot} tone="card" />
                        {week.rarity && (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-0.5 rounded-full bg-card px-1.75 py-0.5 font-mono text-[0.5rem] leading-[1.2] font-extrabold tracking-[.03em] uppercase',
                                    RARITY_INK[week.rarity],
                                )}
                            >
                                <Icon
                                    icon="mdi:sparkle-outline"
                                    width={10}
                                    height={10}
                                    aria-hidden
                                />
                                {RARITY_LABELS[week.rarity]} card
                            </span>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function WeekSummaryButton({
    week,
    expanded,
    disclosureId,
    disabled,
    onToggle,
}: Readonly<{
    week: WeekRow;
    expanded: boolean;
    disclosureId: string;
    disabled: boolean;
    onToggle: () => void;
}>) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onToggle}
            aria-expanded={expanded}
            aria-controls={disclosureId}
            className={cn(
                'focus-ring flex w-full flex-col items-center justify-center gap-0.5 rounded-xs border border-border-strong bg-card px-0.25 py-1.25 font-mono text-foreground',
                disabled && 'text-border-strong',
            )}
        >
            <span className="text-[0.4375rem] leading-[1.2] font-extrabold uppercase">
                WK {week.weekNumber}
            </span>
            {week.runCount > 0 && (
                <>
                    <span className="text-[0.5rem] leading-[1.2] font-bold tabular-nums">
                        {week.totalKm.toFixed(1)}k
                    </span>
                    <span className="mt-0.25 flex items-center gap-0.5">
                        {week.mood && (
                            <span
                                aria-hidden
                                className={cn(
                                    'size-[5px] rounded-full',
                                    MOOD_FILL[week.mood],
                                )}
                            />
                        )}
                        {!disabled && (
                            <Icon
                                icon="mdi:chevron-down"
                                width={7}
                                height={7}
                                className={cn(
                                    'transition-transform',
                                    expanded && 'rotate-180',
                                )}
                                aria-hidden
                            />
                        )}
                    </span>
                </>
            )}
        </button>
    );
}

function DayBox({ cell }: Readonly<{ cell: CalendarCell }>) {
    const hasRun = cell.distance_km !== null && cell.distance_km > 0;
    const className = cn(
        CELL_BASE,
        !cell.is_current_month && 'opacity-32',
        cell.is_today && 'border-horizon-ink bg-horizon/15',
    );

    const inner = (
        <>
            {cell.day}
            {hasRun && cell.mood && (
                <span
                    aria-hidden
                    className={cn(
                        'size-[5px] rounded-full',
                        MOOD_FILL[cell.mood],
                    )}
                    title={MOOD_LABEL[cell.mood]}
                />
            )}
        </>
    );

    const label = describeCell(cell, hasRun);

    if (cell.activity_id !== null) {
        return (
            <Link
                href={activityUrl({ activity_id: cell.activity_id })}
                className={cn(className, 'pressable focus-ring')}
                aria-label={label}
            >
                {inner}
            </Link>
        );
    }

    return (
        <div className={className} aria-label={label}>
            {inner}
        </div>
    );
}

/**
 * The day's numbers survive as the cell's accessible name: the prototype's box
 * shows only a date and a mood dot, so distance and pace have nowhere visible
 * left to live on the grid itself.
 */
function describeCell(cell: CalendarCell, hasRun: boolean): string {
    if (!hasRun) {
        return `${cell.date}: no run`;
    }

    const parts = [`${cell.distance_km} km`];
    if (cell.pace_sec_per_km !== null) {
        parts.push(`${formatPace(cell.pace_sec_per_km)}/km`);
    }
    if (cell.avg_hr !== null) {
        parts.push(`${cell.avg_hr} bpm`);
    }
    if (cell.mood) {
        parts.push(`mood ${MOOD_LABEL[cell.mood]}`);
    }

    return `${cell.date}${cell.is_today ? ' (today)' : ''}: ${parts.join(', ')}`;
}
