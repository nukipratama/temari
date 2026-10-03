import { useEffect, useRef } from 'react';

import { cn } from '@/lib/cn';
import { postJson } from '@/lib/http';
import { formatDurationHMS } from '@/lib/pace';

export interface PrBib {
    label: string;
    value_sec: number | null;
    distance_m: number | null;
    /** The record's key, e.g. "10km" or "longest_run" — sent back to claim the stamp. */
    record_key: string;
    /** True while no {@see App\Models\RecordStamp} row exists yet for this record. */
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
 * either way) and the guarded vibrate-on-mount buzz — then claims the stamp
 * with a fire-and-forget POST so no later view, on any device, animates
 * again. The claim happens after the animation plays, never on render, so a
 * plain GET of the run page stays free of side effects.
 */
export default function PrBibStamp({
    bib,
    className,
}: Readonly<{ bib: PrBib | null; className?: string }>) {
    const claimedRef = useRef(false);

    useEffect(() => {
        if (bib === null || !bib.animate || claimedRef.current) {
            return;
        }
        claimedRef.current = true;
        if (typeof navigator.vibrate === 'function') {
            navigator.vibrate(40);
        }
        void postJson('/api/record-stamps', { record_key: bib.record_key });
    }, [bib]);

    if (bib === null) {
        return null;
    }

    return (
        <div
            className={cn(
                'pr-bib-stamp inline-flex w-fit items-center gap-1.5 rounded-sm bg-horizon px-2.5 py-1 text-label-micro tracking-wide text-sky',
                bib.animate && 'pr-bib-stamp-animate',
                className,
            )}
        >
            {bib.label} · PR · {valueLabel(bib)}
        </div>
    );
}
