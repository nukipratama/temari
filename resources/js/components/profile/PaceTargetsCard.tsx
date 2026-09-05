import { Fragment } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';
import LegacyCard from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';

export interface TrainingPaces {
    easy: number;
    marathon: number;
    threshold: number;
    interval: number;
}

/** Slowest first, so the rail reads easy → hard left to right. */
const MARKERS = [
    { key: 'easy', label: 'easy', below: false },
    { key: 'marathon', label: 'marathon', below: true },
    { key: 'threshold', label: 'tempo', below: false },
    { key: 'interval', label: 'interval', below: true },
] as const;

/**
 * How far from each end a label still anchors to the rail edge rather than
 * centring over its dot. Wide enough that the longest label ("marathon")
 * clears on a phone, where the rail is narrowest relative to the text.
 */
const LABEL_ANCHOR_ZONE = 25;

/**
 * Percentage of its own width to shift a label left. A label centres over its
 * dot, except inside the anchor zones, where it ramps to flush-left at 0% and
 * flush-right at 100% so the end labels never overhang the rail.
 */
function labelShift(left: number): number {
    if (left < LABEL_ANCHOR_ZONE) {
        return (left / LABEL_ANCHOR_ZONE) * 50;
    }
    if (left > 100 - LABEL_ANCHOR_ZONE) {
        return (
            50 + ((left - (100 - LABEL_ANCHOR_ZONE)) / LABEL_ANCHOR_ZONE) * 50
        );
    }

    return 50;
}

/**
 * The four training paces on one rail. The prototype hardcodes each marker's
 * left offset; here the offsets are the paces themselves, linearly placed
 * between the slowest and the fastest, so a runner whose tempo sits unusually
 * close to their marathon pace sees those two markers crowd together.
 */
export default function PaceTargetsCard({
    paces,
}: Readonly<{ paces: TrainingPaces }>) {
    const values = MARKERS.map((m) => paces[m.key]);
    const slowest = Math.max(...values);
    const fastest = Math.min(...values);
    const span = slowest - fastest;

    return (
        <LegacyCard as="section">
            <Eyebrow token="micro" tone="ink-3">
                Training · pace targets · per km
            </Eyebrow>
            <div className="relative mx-4 mt-2.5 h-[78px]">
                <div className="absolute inset-x-0 top-[39px] h-1 rounded-full bg-gradient-to-r from-leaf to-horizon" />
                {MARKERS.map((marker) => {
                    const pace = paces[marker.key];
                    const left =
                        span === 0 ? 50 : ((slowest - pace) / span) * 100;

                    return (
                        <Fragment key={marker.key}>
                            <span
                                className={cn(
                                    'absolute text-center leading-tight whitespace-nowrap',
                                    marker.below ? 'bottom-0' : 'top-0',
                                )}
                                style={{
                                    left: `${left}%`,
                                    transform: `translateX(-${labelShift(left)}%)`,
                                }}
                            >
                                <b className="block font-mono text-xs font-bold tabular-nums text-foreground">
                                    {formatPace(pace)}
                                </b>
                                <span className="block text-label-micro text-text-2">
                                    {marker.label}
                                </span>
                            </span>
                            <i
                                className="absolute top-[37px] size-2 -translate-x-1/2 rounded-full bg-foreground ring-[3px] ring-card"
                                style={{ left: `${left}%` }}
                            />
                        </Fragment>
                    );
                })}
            </div>
        </LegacyCard>
    );
}
