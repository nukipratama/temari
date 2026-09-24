import type { KeyboardEvent } from 'react';

import { useRef } from 'react';

import type { PlanDay } from '@/lib/plan';

import { cn } from '@/lib/cn';
import { formatKm } from '@/lib/pace';
import { weekdayLabel } from '@/lib/plan';

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
    if (day.status === 'missed' || day.status === 'partial') {
        return day.status;
    }
    if (day.status === 'done' || day.status === 'overreached') {
        return 'done';
    }
    return day.session_type;
}

function tileKm(day: PlanDay): string | null {
    const km =
        day.actual_km ??
        (day.session_type === 'rest'
            ? null
            : (day.prescribed_km ?? day.distance_km));

    return km === null ? null : formatKm(km * 1000, 1);
}

const TILE_TONE: Record<TileState, string> = {
    today: 'border-horizon bg-horizon text-sky',
    missed: 'border-ember-ink bg-card text-foreground',
    done: 'border-transparent bg-leaf/18 text-leaf-ink',
    rest: 'border-transparent bg-muted text-text-3',
    planned: 'border-border-strong bg-card text-foreground',
};

const WORD_TONE: Partial<Record<TileState, string>> = {
    missed: 'text-ember-ink',
    planned: 'text-text-2',
};

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
                const selected = day.date === selectedDate;

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
                            'focus-ring flex min-w-0 flex-col items-center gap-1 rounded-sm border py-2',
                            TILE_TONE[state],
                            selected &&
                                'ring-2 ring-foreground ring-offset-1 ring-offset-card',
                        )}
                    >
                        <span className="text-label-micro">
                            {weekdayLabel(day.date)}
                        </span>
                        <span className="font-mono text-xs font-bold tabular-nums">
                            {km ?? '—'}
                        </span>
                        <span
                            className={cn(
                                'w-full truncate text-center font-mono text-[0.5rem] leading-none tracking-tight',
                                WORD_TONE[state],
                            )}
                        >
                            {word}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
