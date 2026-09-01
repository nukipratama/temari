import { useLayoutEffect, useRef, useState } from 'react';

import type { StreamSummaryPartial, StreamSummaryPerKm } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { formatKm, formatPace } from '@/lib/pace';
import { computeBarWidth, paceScale, paceSecOf } from '@/lib/splits';

const CHART_HEIGHT = 116;
/** Padding inside the HR band so the polyline never touches the chart edges. */
const HR_TRACK_TOP = 12;

interface SplitTip {
    key: string;
    x: number;
    pace: string;
    hr: number | null;
    cadence: number | null;
}

interface Bar {
    key: string;
    /** Axis tick under the bar. */
    tick: string;
    pace: string | null;
    hr: number | null;
    cadence: number | null;
    fastest: boolean;
    /** The trailing sub-km remainder, drawn dashed and outside the ranking. */
    partial: boolean;
}

/**
 * Per-km splits as a bar chart — taller bar, faster km — with heart rate
 * traced over it as a dashed line and a tap-to-read tooltip, the way the
 * prototype's `SplitsChartCard` draws them.
 */
export default function SplitsChart({
    rows,
    partial,
    className,
}: Readonly<{
    rows: StreamSummaryPerKm[];
    partial?: StreamSummaryPartial | null;
    className?: string;
}>) {
    const chartRef = useRef<HTMLDivElement>(null);
    const tipRef = useRef<HTMLDivElement>(null);
    const [tip, setTip] = useState<SplitTip | null>(null);

    useLayoutEffect(() => {
        if (!tip || !tipRef.current || !chartRef.current) return;
        const chartWidth = chartRef.current.clientWidth;
        const halfTipWidth = tipRef.current.offsetWidth / 2;
        tipRef.current.style.left = `${Math.min(
            Math.max(tip.x, halfTipWidth + 4),
            chartWidth - halfTipWidth - 4,
        )}px`;
    }, [tip]);

    const { fastest, slowest } = paceScale(rows);
    const fastestRow =
        fastest != null
            ? (rows.find((r) => paceSecOf(r) === fastest) ?? null)
            : null;

    const bars: Bar[] = rows.map((row, i) => ({
        key: row.km != null ? `km-${row.km}` : `row-${i}`,
        tick: `${row.km ?? '?'}`,
        pace: row.pace ?? null,
        hr: row.avg_hr ?? null,
        cadence: row.avg_cadence_spm ?? null,
        fastest: fastest != null && paceSecOf(row) === fastest,
        partial: false,
    }));
    if (partial) {
        bars.push({
            key: 'partial',
            tick: formatKm(partial.distance_m, 1),
            pace: partial.pace ?? null,
            hr: partial.avg_hr ?? null,
            cadence: partial.avg_cadence_spm ?? null,
            fastest: false,
            partial: true,
        });
    }

    // Reuses the splits bar scale rather than a raw min→max stretch: a run whose
    // kms are all within a second or two must read as consistent, not as a
    // dramatic swing (see computeBarWidth's FULL_SPREAD_SEC band).
    const barHeight = (bar: Bar): number =>
        (computeBarWidth(
            bar.pace != null ? paceSecOf({ pace: bar.pace }) : null,
            fastest,
            slowest,
        ) /
            100) *
        CHART_HEIGHT;

    function selectBar(
        event: React.MouseEvent<HTMLButtonElement>,
        bar: Bar,
    ): void {
        if (!chartRef.current || bar.pace === null) return;
        const chartRect = chartRef.current.getBoundingClientRect();
        const barRect = event.currentTarget.getBoundingClientRect();
        setTip((prev) =>
            prev?.key === bar.key
                ? null
                : {
                      key: bar.key,
                      pace: bar.pace as string,
                      hr: bar.hr,
                      cadence: bar.cadence,
                      x: barRect.left + barRect.width / 2 - chartRect.left,
                  },
        );
    }

    return (
        <Card as="section" padding="hero" className={className}>
            <Eyebrow token="micro" tone="ink-2">
                Splits per km
            </Eyebrow>
            <p className="mb-1.5 mt-0.5 font-sans text-xs text-text-3">
                Taller bar, faster km · dashed line tracks heart rate — tap a
                bar for its pace.
            </p>
            <div className="mb-2.5 flex items-center gap-3">
                <span className="flex items-center gap-1 text-label-micro text-text-3">
                    <i
                        aria-hidden
                        className="h-[3px] w-3 rounded-full bg-horizon"
                    />
                    Pace
                </span>
                <span className="flex items-center gap-1 text-label-micro text-text-3">
                    <i
                        aria-hidden
                        className="h-[3px] w-3 rounded-full bg-[repeating-linear-gradient(90deg,var(--color-foreground)_0_3px,transparent_3px_5px)]"
                    />
                    Heart rate
                </span>
            </div>

            <div ref={chartRef} className="relative">
                {tip && (
                    <div
                        ref={tipRef}
                        role="status"
                        className="absolute z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-sm bg-ink px-2.5 py-1.5 text-center shadow-e2"
                        style={{ top: -8, left: tip.x }}
                    >
                        <div className="font-mono text-xs font-bold tabular-nums text-cream">
                            {tip.pace}/km
                        </div>
                        <div className="mt-0.5 font-mono text-xs tabular-nums text-cream/80">
                            ♡ {tip.hr ?? '—'} · {tip.cadence ?? '—'} spm
                        </div>
                        <span
                            aria-hidden
                            className="absolute left-1/2 top-full -translate-x-1/2 border-[5px] border-transparent border-t-ink"
                        />
                    </div>
                )}
                <HrTrace bars={bars} />
                <div
                    className="flex items-end gap-[5px]"
                    style={{ height: CHART_HEIGHT }}
                >
                    {bars.map((bar) => (
                        <button
                            key={bar.key}
                            type="button"
                            onClick={(event) => selectBar(event, bar)}
                            aria-label={`Km ${bar.tick}, ${bar.pace ?? 'no'} pace`}
                            className="focus-ring flex h-full flex-1 flex-col items-center justify-end gap-1 rounded-xs"
                        >
                            {bar.fastest && (
                                <Icon
                                    icon="mdi:star"
                                    width={12}
                                    height={12}
                                    aria-hidden
                                    className="flex-none fill-current text-icon-accent"
                                />
                            )}
                            <div
                                className={cn(
                                    'w-full rounded-t-xs transition-opacity',
                                    bar.partial
                                        ? 'border border-dashed border-border-strong'
                                        : bar.fastest
                                          ? 'bg-horizon'
                                          : 'bg-sky-2',
                                    tip && tip.key !== bar.key && 'opacity-40',
                                )}
                                style={{ height: barHeight(bar) }}
                            />
                            <span className="font-mono text-xs tabular-nums text-text-3">
                                {bar.tick}
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            {fastestRow?.pace != null && fastest != null && (
                <div className="mt-4 flex items-center justify-between gap-3 rounded-sm bg-muted px-3 py-2.5">
                    <div className="flex items-center gap-2">
                        <Icon
                            icon="mdi:star"
                            width={14}
                            height={14}
                            aria-hidden
                            className="flex-none fill-current text-icon-accent"
                        />
                        <span className="font-sans text-xs font-bold text-foreground">
                            Km {fastestRow.km} · fastest
                            {fastestRow.avg_hr != null &&
                                ` · ${fastestRow.avg_hr} bpm`}
                        </span>
                    </div>
                    <span className="font-mono text-sm font-bold tabular-nums text-icon-accent">
                        {formatPace(fastest)}/km
                    </span>
                </div>
            )}
        </Card>
    );
}

/**
 * The heart-rate overlay. Drawn only when at least two bars carry a reading —
 * one point is not a line, and a run with no HR gets no trace at all.
 */
function HrTrace({ bars }: Readonly<{ bars: Bar[] }>) {
    const readings = bars.map((b) => b.hr);
    const present = readings.filter((hr): hr is number => hr != null);
    if (present.length < 2) {
        return null;
    }

    const min = Math.min(...present);
    const max = Math.max(...present);
    const span = max - min || 1;
    const band = CHART_HEIGHT - HR_TRACK_TOP * 2;
    const points = bars
        .map((bar, i) =>
            bar.hr == null
                ? null
                : `${((i + 0.5) / bars.length) * 100},${
                      HR_TRACK_TOP + ((max - bar.hr) / span) * band
                  }`,
        )
        .filter((p): p is string => p !== null)
        .join(' ');

    return (
        <svg
            viewBox={`0 0 100 ${CHART_HEIGHT}`}
            preserveAspectRatio="none"
            aria-hidden
            className="pointer-events-none absolute inset-0 w-full"
            style={{ height: CHART_HEIGHT }}
        >
            <polyline
                points={points}
                fill="none"
                stroke="var(--color-foreground)"
                strokeWidth="1.5"
                strokeDasharray="3 2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
                opacity=".55"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}
