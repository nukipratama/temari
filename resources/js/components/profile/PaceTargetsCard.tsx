import { Fragment } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';
import LegacyCard from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { formatPace, parseNaiveLocalDate } from '@/lib/pace';
import { PR_CATEGORY_LABELS } from '@/lib/pr';

export interface TrainingPaces {
    easy: number;
    marathon: number;
    threshold: number;
    interval: number;
}

/** Which PR these targets were computed from, and when it was set. */
export interface VdotSource {
    category: string;
    /** `Y-m-d`. */
    set_at: string;
    /** No PR inside the estimator's recency window, so an older one still stands in. */
    stale: boolean;
    /** Set only when tempo and interval read a different, more recent record than
     *  easy and marathon do. Null when one record drives all four. */
    quality_category: string | null;
    quality_set_at: string | null;
}

// parseNaiveLocalDate rather than `new Date(iso)`: the latter reads
// '2025-05-19' as UTC midnight, which is the previous month in any
// negative-offset timezone.
function monthYear(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    if (date === null) return iso;

    return date
        .toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
        .toLowerCase();
}

function prLabel(category: string): string {
    return (PR_CATEGORY_LABELS[category] ?? category).toLowerCase();
}

function sourceLine(source: VdotSource): string {
    const set = `from your ${prLabel(source.category)} pr, set ${monthYear(source.set_at)}`;

    if (source.stale) {
        return `${set} · nothing newer to go on`;
    }

    // Easy and marathon stay on the endurance record; tempo and interval read a
    // recent short one, which is a different number and should say so.
    if (source.quality_category !== null && source.quality_set_at !== null) {
        return `easy and marathon ${set} · tempo and interval from your ${prLabel(source.quality_category)} pr, set ${monthYear(source.quality_set_at)}`;
    }

    return set;
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
    source = null,
}: Readonly<{ paces: TrainingPaces; source?: VdotSource | null }>) {
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
            {source && (
                <p className="mx-4 text-xs text-text-3">{sourceLine(source)}</p>
            )}
        </LegacyCard>
    );
}
