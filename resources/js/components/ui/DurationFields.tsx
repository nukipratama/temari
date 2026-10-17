import { Fragment, type ReactNode, useState } from 'react';

import { cn } from '@/lib/cn';
import { inputVariants } from '@/lib/variants';

export interface Duration {
    hours: number;
    minutes: number;
    seconds: number;
}

type Unit = keyof Duration;

const FIELD_LABEL = 'text-label-micro text-text-2';

const UNITS: ReadonlyArray<{ unit: Unit; max: number; suffix: string }> = [
    { unit: 'hours', max: 71, suffix: 'hr' },
    { unit: 'minutes', max: 59, suffix: 'min' },
    { unit: 'seconds', max: 59, suffix: 'sec' },
];

const capitalise = (text: string) => text[0].toUpperCase() + text.slice(1);

export function durationSec({ hours, minutes, seconds }: Duration): number {
    return hours * 3_600 + minutes * 60 + seconds;
}

export function durationFromSec(totalSec: number): Duration {
    return {
        hours: Math.floor(totalSec / 3_600),
        minutes: Math.floor((totalSec % 3_600) / 60),
        seconds: totalSec % 60,
    };
}

/**
 * Hour, minute and optional second inputs. An emptied field stays empty while
 * the athlete types and reads as 0 in `onChange` straight away; blur settles
 * the display on that 0.
 */
export default function DurationFields({
    value,
    onChange,
    withSeconds = false,
    labelPrefix = '',
    className,
    children,
}: Readonly<{
    value: Duration;
    onChange: (next: Duration) => void;
    withSeconds?: boolean;
    /** Prepended to each aria-label, e.g. `finish ` gives "Finish hours". */
    labelPrefix?: string;
    className?: string;
    /** Extra controls that sit in the same row after the last field. */
    children?: ReactNode;
}>) {
    const [emptied, setEmptied] = useState<ReadonlySet<Unit>>(() => new Set());

    const setEmptiedFlag = (unit: Unit, isEmpty: boolean) =>
        setEmptied((prev) => {
            if (prev.has(unit) === isEmpty) {
                return prev;
            }
            const next = new Set(prev);
            if (isEmpty) {
                next.add(unit);
            } else {
                next.delete(unit);
            }

            return next;
        });

    return (
        <div className={cn('flex items-center gap-1.5', className)}>
            {UNITS.filter(({ unit }) => withSeconds || unit !== 'seconds').map(
                ({ unit, max, suffix }) => (
                    <Fragment key={unit}>
                        <input
                            type="number"
                            min={0}
                            max={max}
                            value={emptied.has(unit) ? '' : value[unit]}
                            onChange={(e) => {
                                const raw = e.target.value;
                                setEmptiedFlag(unit, raw === '');
                                onChange({
                                    ...value,
                                    [unit]: raw === '' ? 0 : Number(raw),
                                });
                            }}
                            onBlur={() => setEmptiedFlag(unit, false)}
                            aria-label={capitalise(`${labelPrefix}${unit}`)}
                            className={cn(
                                inputVariants({ size: 'sm' }),
                                'w-16 text-center',
                            )}
                        />
                        <span className={FIELD_LABEL}>{suffix}</span>
                    </Fragment>
                ),
            )}
            {children}
        </div>
    );
}
