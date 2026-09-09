import { Fragment, useLayoutEffect, useRef, useState } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';
import LegacyCard from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { formatPace, parseNaiveLocalDate } from '@/lib/pace';
import { layoutPaceLabels } from '@/lib/paceRail';
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

/** Breathing room kept on each side of a label, in pixels. */
const LABEL_PADDING = 8;

/** Stands in for the rail until it is measured, and where there is no layout. */
const ASSUMED_RAIL_WIDTH = 260;

/**
 * A label's room before it is measured: the wider of its two lines, at 11px
 * bold over 12px mono.
 */
function estimateLabelWidth(label: string, pace: string): number {
    return (
        ((Math.max(label.length * 6.6, pace.length * 7.2) + LABEL_PADDING) /
            ASSUMED_RAIL_WIDTH) *
        100
    );
}

/**
 * The four training paces on one rail. The prototype hardcodes each marker's
 * left offset; here the offsets are the paces themselves, linearly placed
 * between the slowest and the fastest. Dots stay on those offsets; labels
 * centre on them and give way to each other when two paces sit close enough
 * that their labels would collide.
 */
export default function PaceTargetsCard({
    paces,
    source = null,
}: Readonly<{ paces: TrainingPaces; source?: VdotSource | null }>) {
    const [rail, setRail] = useState<HTMLDivElement | null>(null);
    const [widths, setWidths] = useState<number[] | null>(null);
    const labelRef = useRef<(HTMLSpanElement | null)[]>([]);

    useLayoutEffect(() => {
        if (!rail) {
            return;
        }
        const measure = () => {
            const railWidth = rail.clientWidth;
            if (railWidth === 0) {
                return;
            }
            setWidths(
                labelRef.current.map(
                    (label) =>
                        (((label?.offsetWidth ?? 0) + LABEL_PADDING) /
                            railWidth) *
                        100,
                ),
            );
        };
        const observer = new ResizeObserver(measure);
        observer.observe(rail);

        return () => observer.disconnect();
    }, [rail]);

    const values = MARKERS.map((m) => paces[m.key]);
    const slowest = Math.max(...values);
    const fastest = Math.min(...values);
    const span = slowest - fastest;

    const layout = layoutPaceLabels(
        MARKERS.map((marker, index) => ({
            below: marker.below,
            position:
                span === 0 ? 50 : ((slowest - paces[marker.key]) / span) * 100,
            width:
                widths?.[index] ??
                estimateLabelWidth(marker.label, formatPace(paces[marker.key])),
        })),
    );

    return (
        <LegacyCard as="section">
            <Eyebrow token="micro" tone="ink-3">
                Training · pace targets · per km
            </Eyebrow>
            <div ref={setRail} className="relative mx-4 mt-2.5 h-[78px]">
                <div className="absolute inset-x-0 top-[39px] h-1 rounded-full bg-gradient-to-r from-leaf to-horizon" />
                {MARKERS.map((marker, index) => {
                    const { position, left } = layout[index];

                    return (
                        <Fragment key={marker.key}>
                            <span
                                ref={(element) => {
                                    labelRef.current[index] = element;
                                }}
                                className={cn(
                                    'absolute text-center leading-tight whitespace-nowrap',
                                    marker.below ? 'bottom-0' : 'top-0',
                                )}
                                style={{ left: `${left}%` }}
                            >
                                <b className="block font-mono text-xs font-bold tabular-nums text-foreground">
                                    {formatPace(paces[marker.key])}
                                </b>
                                <span className="block text-label-micro text-text-2">
                                    {marker.label}
                                </span>
                            </span>
                            <i
                                className="absolute top-[37px] size-2 -translate-x-1/2 rounded-full bg-foreground ring-[3px] ring-card"
                                style={{ left: `${position}%` }}
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
