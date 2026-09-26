import type { KeyboardEvent } from 'react';

import { useRef } from 'react';

import type { PlanDay } from '@/lib/plan';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { EFFORT_EDGE_CLASS, sessionTypeEffort } from '@/lib/effort';
import { formatKm } from '@/lib/pace';
import { dayStatusGlyph, weekdayLabel } from '@/lib/plan';

type TileState = 'today' | 'missed' | 'done' | 'rest' | 'planned';

function tileState(day: PlanDay, today: string): TileState {
    if (day.date === today) {
        return 'today';
    }
    if (
        (day.session_type === 'rest' && !day.ran_anyway) ||
        day.skipped ||
        day.status === 'skip'
    ) {
        return 'rest';
    }
    if (day.status === 'missed') {
        return 'missed';
    }
    if (['done', 'partial', 'overreached'].includes(day.status)) {
        return 'done';
    }
    return 'planned';
}

function tileWord(day: PlanDay): string {
    if (day.session_type === 'rest' && !day.ran_anyway) {
        return 'rest';
    }
    if (day.skipped || day.status === 'skip') {
        return 'skipped';
    }
    if (
        day.status === 'missed' ||
        day.status === 'partial' ||
        day.status === 'overreached'
    ) {
        return day.status;
    }
    if (day.status === 'done') {
        return 'done';
    }
    return day.session_type;
}

/**
 * The tile's visible word — dropped once the day has a status glyph to show
 * instead. A still-ahead day keeps its word, since it has no status yet, only
 * a session type.
 */
function tileVisibleWord(day: PlanDay): string | null {
    return dayStatusGlyph(day) !== null ? null : tileWord(day);
}

const SHORT_WORD: Record<string, string> = {
    interval: 'reps',
};

function tileKm(day: PlanDay): string | null {
    const km =
        day.actual_km ??
        (day.session_type === 'rest'
            ? null
            : (day.prescribed_km ?? day.distance_km));

    return km === null ? null : formatKm(km * 1000, 1);
}

/**
 * The week as seven labelled tiles, Monday to Sunday: the weekday, the km
 * (what was run, else what is asked) and one word for how the day stands.
 * A tab list: arrow keys, Home and End move the selection, which the caller
 * owns and shows in the panel the tiles control.
 */
export default function WeekStrip({
    days,
    today,
    selectedDate,
    onSelect,
    tabId,
    panelId,
}: Readonly<{
    days: PlanDay[];
    today: string;
    selectedDate: string | null;
    onSelect: (date: string) => void;
    tabId: (date: string) => string;
    panelId: string;
}>) {
    const tabsRef = useRef<(HTMLButtonElement | null)[]>([]);

    const onKeyDown = (event: KeyboardEvent, index: number) => {
        const target = (
            {
                ArrowRight: (index + 1) % days.length,
                ArrowLeft: (index - 1 + days.length) % days.length,
                Home: 0,
                End: days.length - 1,
            } as Record<string, number | undefined>
        )[event.key];
        if (target === undefined) {
            return;
        }
        event.preventDefault();
        onSelect(days[target].date);
        tabsRef.current[target]?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label="days this week"
            className="grid grid-cols-7 gap-1"
        >
            {days.map((day, index) => {
                const state = tileState(day, today);
                const km = tileKm(day);
                const word = tileWord(day);
                const visibleWord = tileVisibleWord(day);
                const glyph = dayStatusGlyph(day);
                const selected = day.date === selectedDate;
                const isToday = state === 'today';
                const effort = sessionTypeEffort(day.session_type);

                return (
                    <button
                        key={day.date}
                        ref={(el) => {
                            tabsRef.current[index] = el;
                        }}
                        type="button"
                        role="tab"
                        id={tabId(day.date)}
                        aria-selected={selected}
                        aria-controls={panelId}
                        aria-label={[
                            weekdayLabel(day.date),
                            km === null ? null : `${km} km`,
                            word,
                            state === 'today' ? 'today' : null,
                        ]
                            .filter((part) => part !== null)
                            .join(', ')}
                        tabIndex={selected ? 0 : -1}
                        data-state={state}
                        onClick={() => onSelect(day.date)}
                        onKeyDown={(event) => onKeyDown(event, index)}
                        className={cn(
                            'focus-ring relative flex min-w-0 flex-col items-center gap-1 overflow-hidden rounded-sm border border-border bg-card py-2 text-foreground',
                            selected &&
                                (isToday
                                    ? 'ring-2 ring-icon-accent ring-offset-1 ring-offset-card'
                                    : 'ring-2 ring-foreground ring-offset-1 ring-offset-card'),
                            !selected &&
                                isToday &&
                                'ring-[1.5px] ring-inset ring-icon-accent',
                        )}
                    >
                        <span className="text-label-micro">
                            {weekdayLabel(day.date)}
                        </span>
                        <span className="font-mono text-xs font-bold tabular-nums">
                            {km ?? '—'}
                        </span>
                        {glyph !== null ? (
                            <Icon
                                icon={glyph}
                                width={11}
                                height={11}
                                aria-hidden
                            />
                        ) : (
                            visibleWord !== null && (
                                <span className="text-meta w-full truncate text-center leading-none text-text-2">
                                    {SHORT_WORD[visibleWord] ?? visibleWord}
                                </span>
                            )
                        )}
                        <span
                            aria-hidden
                            className={cn(
                                'absolute inset-x-0 bottom-0',
                                EFFORT_EDGE_CLASS[effort],
                            )}
                        />
                    </button>
                );
            })}
        </div>
    );
}
