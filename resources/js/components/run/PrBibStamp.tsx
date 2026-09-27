import { useEffect, useRef } from 'react';

import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';

export interface PrBib {
    label: string;
    value_sec: number | null;
    distance_m: number | null;
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
 * The one-time race-bib stamp on a run that currently holds a tracked
 * record: punches in on mount via a plain CSS animation
 * (`prefers-reduced-motion` shows it static), then stays as a plain badge.
 * `bib` is only ever non-null on the one render that resolved an unseen
 * record server-side, so the vibrate-on-mount effect fires at most once per
 * record, ever.
 */
export default function PrBibStamp({
    bib,
    className,
}: Readonly<{ bib: PrBib | null; className?: string }>) {
    const vibratedRef = useRef(false);

    useEffect(() => {
        if (bib === null || vibratedRef.current) {
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
                className,
            )}
        >
            {bib.label} · PR · {valueLabel(bib)}
        </div>
    );
}
