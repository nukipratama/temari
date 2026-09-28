import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

import type { CalendarCell } from '@/pages/Activities/useCalendar';

import { Icon } from '@/components/ui/Icon';
import Sheet from '@/components/ui/Sheet';
import { cn } from '@/lib/cn';
import { EFFORT_ICON_CLASS } from '@/lib/effort';
import { MOOD_LABEL } from '@/lib/mood';
import { formatNaiveMonthDayId, formatPace } from '@/lib/pace';
import { activityUrl } from '@/lib/routes';
import {
    EFFORT_FILL,
    EFFORT_WORD,
    kmLabel,
    runCount,
} from '@/pages/Activities/calendarBars';

/**
 * A day with 2+ runs opens this sheet instead of linking straight to a
 * detail page: each run's name, distance, pace, effort and mood as a word,
 * each linking on to its own detail.
 */
export default function DayRunsSheet({
    cell,
    onClose,
}: Readonly<{ cell: CalendarCell | null; onClose: () => void }>) {
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
                    </p>
                    <ul className="mt-3 divide-y divide-dashed divide-border">
                        {cell.runs.map((run) => (
                            <li key={run.activity_id}>
                                <Link
                                    href={activityUrl({
                                        activity_id: run.activity_id,
                                    })}
                                    className="pressable focus-ring -mx-2 flex items-center gap-3 rounded-md px-2 py-3 hover:bg-muted"
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'block h-9 w-[3px] flex-none',
                                            run.effort === 'rest'
                                                ? 'border-l-2 border-dashed border-border-strong'
                                                : EFFORT_FILL[run.effort],
                                        )}
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
