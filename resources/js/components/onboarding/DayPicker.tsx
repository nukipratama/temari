import { motion } from 'framer-motion';
import { Fragment, type ReactElement, type ReactNode } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { pressShrink } from '@/lib/motion';

/**
 * Lays out a row of `DayCell`s connected by a stepper-style line. Each item
 * must carry its own `key` (as every call site's `DayCell key={...}`
 * already does) so the connector wrapper can reuse it.
 */
export function DayRow({ items }: Readonly<{ items: ReactElement[] }>) {
    return (
        <div className="flex items-start">
            {items.map((item, index) => (
                <Fragment key={item.key}>
                    {item}
                    {index < items.length - 1 && (
                        <div
                            aria-hidden
                            className="mt-[22px] h-0.5 flex-1 rounded-full bg-border-strong"
                        />
                    )}
                </Fragment>
            ))}
        </div>
    );
}

/**
 * A single day toggle. `longRun` is the persisted, already-chosen long-run
 * day (big, filled, flag glyph); `flagCandidate` marks the equally-weighted
 * "tap to make this the long run" state before one is chosen yet.
 */
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
    longRun?: boolean;
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
        icon = (
            <Icon
                icon="mdi:flag-checkered"
                width={14}
                height={14}
                aria-hidden
            />
        );
    } else if (active) {
        circleSize = 'size-8';
        circleTone = 'border-icon-accent bg-horizon/10 text-icon-accent';
        icon = flagCandidate ? (
            <Icon
                icon="mdi:flag-checkered"
                width={12}
                height={12}
                aria-hidden
            />
        ) : (
            <Icon icon="mdi:run" width={12} height={12} aria-hidden />
        );
    }

    return (
        <motion.button
            type="button"
            disabled={disabled}
            onClick={onClick}
            whileTap={pressShrink}
            className="focus-ring flex flex-none flex-col items-center gap-1 rounded-xl"
        >
            <span className="flex h-11 items-center justify-center">
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
                    'text-label-micro',
                    longRun || active ? 'text-icon-accent' : 'text-foreground',
                    disabled && 'opacity-40',
                )}
            >
                {label}
            </span>
        </motion.button>
    );
}
