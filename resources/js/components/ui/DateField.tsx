import { useRef, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { useCoarsePointer } from '@/hooks/useCoarsePointer';
import { usePopover } from '@/hooks/usePopover';
import { cn } from '@/lib/cn';
import { addMonths, isSameMonth, monthGrid, WEEKDAYS } from '@/lib/monthGrid';
import { parseNaiveLocalDate, todayLocalIso } from '@/lib/pace';
import { inputVariants } from '@/lib/variants';

interface DateFieldProps {
    id: string;
    value: string;
    onChange: (iso: string) => void;
    /** Earliest selectable day, as Y-m-d. Enforced by the input and the grid alike. */
    min?: string;
    required?: boolean;
    className?: string;
}

/**
 * A date field that keeps the native input and adds a calendar of our own on
 * pointer devices.
 *
 * The input is never replaced by a button, because it is doing real work: the
 * race form is a genuine `<form onSubmit>`, so `required` and `min` run the
 * browser's own constraint validation and block submission before the handler
 * is reached. A button would have dropped that silently. On a fine pointer the
 * webkit picker indicator is hidden and our own trigger takes its place; on a
 * coarse pointer nothing changes at all, since the OS date sheet beats anything
 * we would draw — bigger targets, the platform's own locale handling, and the
 * interaction people already know.
 */
export default function DateField({
    id,
    value,
    onChange,
    min,
    required = false,
    className,
}: Readonly<DateFieldProps>) {
    const coarse = useCoarsePointer();
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    usePopover(open, containerRef, () => setOpen(false));

    return (
        <div ref={containerRef} className={cn('relative', className)}>
            <input
                id={id}
                type="date"
                required={required}
                min={min}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={cn(
                    inputVariants(),
                    !coarse &&
                        'pr-9 [&::-webkit-calendar-picker-indicator]:hidden',
                )}
            />
            {!coarse && (
                <button
                    type="button"
                    aria-label="Choose a date"
                    aria-expanded={open}
                    onClick={() => setOpen((wasOpen) => !wasOpen)}
                    className="focus-ring absolute inset-y-0 right-0 flex w-9 items-center justify-center rounded-sm text-text-3 transition-colors hover:text-foreground"
                >
                    <Icon
                        icon="mdi:calendar-blank-outline"
                        width={16}
                        height={16}
                        aria-hidden
                    />
                </button>
            )}
            {!coarse && open && (
                <CalendarPopover
                    value={value}
                    min={min}
                    onPick={(iso) => {
                        onChange(iso);
                        setOpen(false);
                    }}
                />
            )}
        </div>
    );
}

function CalendarPopover({
    value,
    min,
    onPick,
}: Readonly<{
    value: string;
    min?: string;
    onPick: (iso: string) => void;
}>) {
    const today = todayLocalIso();
    const [month, setMonth] = useState(() => value || min || today);
    const weeks = monthGrid(month);
    const monthDate = parseNaiveLocalDate(month);
    const label =
        monthDate === null
            ? ''
            : monthDate
                  .toLocaleDateString('en-US', {
                      month: 'long',
                      year: 'numeric',
                  })
                  .toLowerCase();

    return (
        <div
            role="dialog"
            aria-label="Choose a date"
            className="absolute top-full right-0 z-30 mt-1.5 w-64 rounded-md border border-border-strong bg-popover p-3 shadow-e3"
        >
            <div className="flex items-center justify-between gap-2">
                <MonthStep
                    label="Previous month"
                    icon="mdi:chevron-left"
                    onClick={() => setMonth(addMonths(month, -1))}
                />
                <span className="text-label-micro text-foreground">
                    {label}
                </span>
                <MonthStep
                    label="Next month"
                    icon="mdi:chevron-right"
                    onClick={() => setMonth(addMonths(month, 1))}
                />
            </div>

            <div className="mt-2 grid grid-cols-7 gap-0.5">
                {WEEKDAYS.map((day) => (
                    <span
                        key={day.name}
                        aria-hidden
                        className="text-label-micro flex h-6 items-center justify-center text-text-3"
                    >
                        {day.initial}
                    </span>
                ))}
                {weeks.flat().map((iso) => (
                    <DayCell
                        key={iso}
                        iso={iso}
                        month={month}
                        value={value}
                        today={today}
                        disabled={min !== undefined && iso < min}
                        onPick={onPick}
                    />
                ))}
            </div>
        </div>
    );
}

function MonthStep({
    label,
    icon,
    onClick,
}: Readonly<{ label: string; icon: string; onClick: () => void }>) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className="focus-ring flex size-6 flex-none items-center justify-center rounded-full bg-muted text-foreground transition-colors hover:bg-accent"
        >
            <Icon icon={icon} width={14} height={14} aria-hidden />
        </button>
    );
}

function DayCell({
    iso,
    month,
    value,
    today,
    disabled,
    onPick,
}: Readonly<{
    iso: string;
    month: string;
    value: string;
    today: string;
    disabled: boolean;
    onPick: (iso: string) => void;
}>) {
    const selected = iso === value;
    const outside = !isSameMonth(iso, month);
    // A six-week grid shows two days numbered "5", so the visible number alone
    // names neither of them. The full date is the accessible name; the number
    // is decoration beside it.
    const parsed = parseNaiveLocalDate(iso);
    const fullDate =
        parsed?.toLocaleDateString('en-US', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        }) ?? iso;

    return (
        <button
            type="button"
            disabled={disabled}
            aria-label={fullDate}
            aria-current={iso === today ? 'date' : undefined}
            aria-pressed={selected}
            onClick={() => onPick(iso)}
            className={cn(
                'focus-ring flex h-7 items-center justify-center rounded-sm font-mono text-xs tabular-nums transition-colors',
                'disabled:cursor-not-allowed disabled:opacity-35',
                selected && 'bg-horizon text-sky',
                !selected && outside && 'text-text-3',
                !selected && !outside && 'text-foreground hover:bg-muted',
                !selected && iso === today && 'ring-1 ring-icon-accent',
            )}
        >
            <span aria-hidden>{Number(iso.slice(8, 10))}</span>
        </button>
    );
}
