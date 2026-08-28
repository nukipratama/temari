import { motion } from 'framer-motion';
import { Flag, Footprints, type LucideIcon } from 'lucide-react';
import { Children, Fragment, type ReactNode } from 'react';

import { pressShrink } from '@/lib/motion';
import { cn } from '@/lib/utils';

// The circle-zone every DayCell reserves before its label — fixed regardless
// of the circle's own diameter, so the stepper-style connector segments
// (which key off this same height) land on every circle's true center.
const DAY_CELL_CIRCLE_ZONE = 'h-11';

export function DayRow({ children }: Readonly<{ children: ReactNode }>) {
    const items = Children.toArray(children);
    return (
        <div className="flex items-start">
            {items.map((child, i) => (
                <Fragment
                    key={
                        typeof child === 'object' && 'key' in child
                            ? (child.key ?? i)
                            : i
                    }
                >
                    {child}
                    {i < items.length - 1 && (
                        <div
                            aria-hidden
                            className={cn(
                                'mt-[22px] h-0.5 flex-1 rounded-full bg-border-strong',
                            )}
                        />
                    )}
                </Fragment>
            ))}
        </div>
    );
}

export function DayCell({
    label,
    active,
    longRun = false,
    flagCandidate = false,
    disabled = false,
    onClick,
}: Readonly<{
    label: string;
    active: boolean;
    /** The persisted, already-chosen long-run day — the one big, glowing, solid-filled cell. */
    longRun?: boolean;
    /** An equally-weighted "tap to make this the long run" option, before any one is chosen yet. */
    flagCandidate?: boolean;
    disabled?: boolean;
    onClick: () => void;
}>) {
    let circleSize = 'size-7';
    let circleTone = 'border-border-strong bg-card text-foreground';
    let icon: ReactNode = null;
    if (longRun) {
        circleSize = 'size-10';
        circleTone =
            'border-icon-accent bg-icon-accent text-btn-primary-fg ring-4 ring-horizon/20';
        icon = <Flag className="size-3.5" aria-hidden />;
    } else if (active) {
        circleSize = 'size-8';
        circleTone = 'border-icon-accent bg-horizon/20 text-icon-accent';
        icon = flagCandidate ? (
            <Flag className="size-3" aria-hidden />
        ) : (
            <Footprints className="size-3" aria-hidden />
        );
    }
    return (
        <motion.button
            type="button"
            disabled={disabled}
            onClick={onClick}
            whileTap={pressShrink}
            className="flex flex-none flex-col items-center gap-1 rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring/30"
        >
            <span
                className={cn(
                    'flex items-center justify-center',
                    DAY_CELL_CIRCLE_ZONE,
                )}
            >
                <span
                    className={cn(
                        'flex flex-none items-center justify-center rounded-full border-2',
                        circleSize,
                        circleTone,
                        disabled && 'opacity-40',
                    )}
                >
                    {icon}
                </span>
            </span>
            <span
                className={cn(
                    'font-mono text-[8.5px] leading-[1.2] font-bold uppercase',
                    longRun || active ? 'text-icon-accent' : 'text-foreground',
                    disabled && 'opacity-40',
                )}
            >
                {label}
            </span>
        </motion.button>
    );
}

export function IconChoiceCard({
    icon: Icon,
    label,
    description,
    active,
    onClick,
}: Readonly<{
    icon: LucideIcon;
    label: string;
    description?: string;
    active: boolean;
    onClick: () => void;
}>) {
    return (
        <motion.button
            type="button"
            onClick={onClick}
            whileTap={pressShrink}
            className={cn(
                'flex w-full items-center gap-3 rounded-[14px] border px-3.5 py-3 text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/30',
                active
                    ? 'border-icon-accent bg-horizon/20'
                    : 'border-border-strong bg-card shadow-e1',
            )}
        >
            <span
                className={cn(
                    'flex size-9 flex-none items-center justify-center rounded-full',
                    active
                        ? 'bg-icon-accent text-btn-primary-fg'
                        : 'bg-muted text-foreground',
                )}
            >
                <Icon className="size-[18px]" aria-hidden />
            </span>
            <span className="min-w-0 flex-1">
                <b
                    className={cn(
                        'block font-sans text-[13px] leading-[1.2] font-bold',
                        active ? 'text-icon-accent' : 'text-foreground',
                    )}
                >
                    {label}
                </b>
                {description && (
                    <span className="mt-0.5 block text-[11px] leading-[1.35] text-foreground">
                        {description}
                    </span>
                )}
            </span>
        </motion.button>
    );
}

export function SessionsDial({
    options,
    value,
    onChange,
}: Readonly<{
    options: readonly number[];
    value: number;
    onChange: (n: number) => void;
}>) {
    return (
        <div className="flex items-end gap-2.5">
            {options.map((n, i) => {
                const filled = n <= value;
                return (
                    <div key={n} className="flex flex-col items-center gap-1.5">
                        <motion.button
                            type="button"
                            onClick={() => onChange(n)}
                            whileTap={pressShrink}
                            aria-pressed={n === value}
                            aria-label={`${n} sessions a week`}
                            style={{ height: `${26 + i * 9}px` }}
                            className={cn(
                                'w-7 rounded-t-md border-2 outline-none focus-visible:ring-3 focus-visible:ring-ring/30',
                                filled
                                    ? 'border-icon-accent bg-icon-accent'
                                    : 'border-border-strong bg-transparent',
                            )}
                        />
                        <span
                            className={cn(
                                'font-mono text-[10px] leading-[1.2] font-bold',
                                n === value
                                    ? 'text-icon-accent'
                                    : 'text-foreground',
                            )}
                        >
                            {n}x
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
