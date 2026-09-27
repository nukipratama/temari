import { useEffect, useRef } from 'react';

import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';

export interface PrBib {
    label: string;
    value_sec: number | null;
    distance_m: number | null;
    /** True only on the render that just claimed this record's one-time animation. */
    animate: boolean;
}

function valueLabel(bib: PrBib): string {
    if (bib.value_sec !== null) {
        return formatDurationHMS(bib.value_sec);
    }
    if (bib.distance_m !== null) {
        return `${(bib.distance_m / 1000).toFixed(1)} km`;
    }
    return '';
}

/**
 * The race-bib badge on a run that currently holds a tracked record: shown
 * on every view, permanently. Only the render that resolved `animate: true`
 * plays the punch-in CSS animation (`prefers-reduced-motion` shows it static
 * either way) and the guarded vibrate-on-mount buzz — every later view, on
 * any device, renders the same badge but static.
 */
export default function PrBibStamp({
    bib,
    className,
}: Readonly<{ bib: PrBib | null; className?: string }>) {
    const vibratedRef = useRef(false);

    useEffect(() => {
        if (bib === null || !bib.animate || vibratedRef.current) {
            return;
        }
        vibratedRef.current = true;
        if (typeof navigator.vibrate === 'function') {
            navigator.vibrate(40);
        }
    }, [bib]);

    if (bib === null) {
        return null;
    }

    return (
        <div
            className={cn(
                'pr-bib-stamp inline-flex w-fit items-center gap-1.5 rounded-sm bg-horizon px-2.5 py-1 font-mono text-label-micro font-bold uppercase tracking-wide text-sky',
                bib.animate && 'pr-bib-stamp-animate',
                className,
            )}
        >
            {bib.label} · PR · {valueLabel(bib)}
        </div>
    );
}
