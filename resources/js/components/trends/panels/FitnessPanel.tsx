import type { Plugin, TooltipItem } from 'chart.js';

import { Suspense, useMemo } from 'react';

import type { FormStatus } from '@/types/inertia';

import Skeleton from '@/components/ui/Skeleton';
import { useIsDarkGround } from '@/hooks/useIsDarkGround';
import { CHART_GROUND, PALETTE } from '@/lib/chartTokens';
import { formStatusFor } from '@/lib/formStatus';
import { lazyIsland } from '@/lib/lazyIsland';
import { formatNaiveMonthDayId } from '@/lib/pace';

// Chart.js core + its scale/element registration live inside this lazy
// module, mirroring CtlTrendChart/ProgressionChart so nothing chart-related
// enters this page's own chunk either.
const Line = lazyIsland(() => import('@/components/collection/LineChart'));

export interface FitnessTrendPoint {
    date: string;
    atl: number;
    ctl: number;
}

export interface FitnessChartAnnotations {
    /** Dates (Y-m-d) whose plan phase was a deload week. */
    deload: ReadonlyArray<string>;
    /** Dates (Y-m-d) of a race session. */
    race: ReadonlyArray<string>;
}

const NO_ANNOTATIONS: FitnessChartAnnotations = { deload: [], race: [] };

interface FitnessPanelProps {
    /** The full 365-day series ({@link FitnessTrendPoint}), oldest first. */
    trend: ReadonlyArray<FitnessTrendPoint>;
    /** Only the deload marker is drawn here — direction A keeps race day off
     *  this chart, see the "vs race day" comparison instead. */
    annotations?: FitnessChartAnnotations;
    /** Trailing days to shade, so "a month ago" is a place on the line. */
    highlightDays?: number;
    className?: string;
}

type BandBucket = 'fresh' | 'balanced' | 'tired';

// Fatigued and overreaching share one bucket: the strip is a glance, not a
// second read of the fitness numbers, and the cost section above already
// carries the finer-grained form_status word.
const BAND_BUCKET: Record<FormStatus, BandBucket> = {
    fresh: 'fresh',
    optimal: 'balanced',
    fatigued: 'tired',
    overreaching: 'tired',
};

const BAND_COLOR: Record<BandBucket, string> = {
    fresh: PALETTE.leaf,
    balanced: PALETTE.stone,
    tired: PALETTE.ember,
};

const BAND_LABEL: Record<BandBucket, string> = {
    fresh: 'fresh',
    balanced: 'in balance',
    tired: 'tired',
};

interface BandRun {
    bucket: BandBucket;
    length: number;
    /** The run's first date — a stable, real key, unlike its array index. */
    startDate: string;
}

/** Collapses the per-day form-status band into contiguous runs, so the strip
 *  is a handful of flex segments rather than one per day. */
function bandRuns(trend: ReadonlyArray<FitnessTrendPoint>): BandRun[] {
    const runs: BandRun[] = [];
    for (const point of trend) {
        const bucket =
            BAND_BUCKET[formStatusFor(point.ctl - point.atl, point.ctl)];
        const last = runs[runs.length - 1];
        if (last && last.bucket === bucket) {
            last.length += 1;
        } else {
            runs.push({ bucket, length: 1, startDate: point.date });
        }
    }
    return runs;
}

/** Draws a vertical line at each deload-week index — the fitness chart's one
 *  marker in direction A (race day lives in its own comparison instead). */
function deloadMarkerPlugin(indices: number[], color: string): Plugin<'line'> {
    return {
        id: 'trendDeloadMarker',
        afterDatasetsDraw(chart) {
            if (indices.length === 0) return;
            const { ctx, chartArea, scales } = chart;
            const xScale = scales.x;
            if (!xScale || !chartArea) return;

            ctx.save();
            ctx.strokeStyle = color;
            ctx.lineWidth = 1;
            ctx.setLineDash([3, 2]);
            indices.forEach((index) => {
                const x = xScale.getPixelForValue(index);
                ctx.beginPath();
                ctx.moveTo(x, chartArea.top);
                ctx.lineTo(x, chartArea.bottom);
                ctx.stroke();
            });
            ctx.restore();
        },
    };
}

/** Shades the trailing `days` of the plot area, so "a month ago" reads as a
 *  place on the line rather than an abstract number. */
function highlightPlugin(
    days: number,
    total: number,
    color: string,
): Plugin<'line'> {
    return {
        id: 'trendHighlight',
        beforeDatasetsDraw(chart) {
            if (days <= 0 || total === 0) return;
            const { ctx, chartArea, scales } = chart;
            const xScale = scales.x;
            if (!xScale || !chartArea) return;

            const fromIndex = Math.max(0, total - days);
            const x0 = xScale.getPixelForValue(fromIndex);
            ctx.save();
            ctx.fillStyle = color;
            ctx.fillRect(
                x0,
                chartArea.top,
                chartArea.right - x0,
                chartArea.bottom - chartArea.top,
            );
            ctx.restore();
        },
    };
}

/**
 * The fitness line "vs a month ago" owns: one CTL series over the last 365
 * days with a categorical form band beneath it, the trailing window shaded,
 * and the deload marker kept. No ATL line, no stat tiles, no badge chips —
 * those moved to the comparison cards around it, or off the page entirely
 * (#967). Chart.js stays; the band is a plain flex strip rather than a
 * second dataset, since a categorical read has no business being a line.
 */
export default function FitnessPanel({
    trend,
    annotations = NO_ANNOTATIONS,
    highlightDays = 30,
    className,
}: Readonly<FitnessPanelProps>) {
    const isDark = useIsDarkGround();
    const ground = isDark ? CHART_GROUND.dark : CHART_GROUND.light;

    const deloadIndices = useMemo(() => {
        const deload = new Set(annotations.deload);
        const indices: number[] = [];
        trend.forEach((point, index) => {
            if (deload.has(point.date)) indices.push(index);
        });
        return indices;
    }, [trend, annotations]);

    const chartPlugins = useMemo(
        () => [
            deloadMarkerPlugin(deloadIndices, PALETTE.stone),
            highlightPlugin(
                highlightDays,
                trend.length,
                `${PALETTE.horizon}22`,
            ),
        ],
        [deloadIndices, highlightDays, trend.length],
    );

    const labels = useMemo(
        () => trend.map((p) => formatNaiveMonthDayId(p.date)),
        [trend],
    );

    const data = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'fitness',
                    data: trend.map((p) => p.ctl),
                    borderColor: ground.line,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.25,
                    fill: false,
                },
            ],
        }),
        [trend, labels, ground.line],
    );

    const options = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 900, easing: 'easeOutQuart' as const },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items: TooltipItem<'line'>[]): string => {
                            const point = trend[items[0]?.dataIndex ?? -1];
                            return point
                                ? formatNaiveMonthDayId(point.date)
                                : '';
                        },
                        label: (item: TooltipItem<'line'>): string =>
                            `fitness: ${Math.round(item.parsed.y ?? 0)}`,
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: ground.tick,
                        font: { size: 9 },
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 6,
                    },
                    border: { display: false },
                },
                y: {
                    grid: { color: ground.grid },
                    ticks: {
                        color: ground.tick,
                        font: { size: 10 },
                        maxTicksLimit: 4,
                    },
                    border: { display: false },
                },
            },
        }),
        [ground, trend],
    );

    if (trend.length === 0) {
        return (
            <p className={className ?? 'text-sm text-text-2'}>
                not enough training history yet to draw a trend.
            </p>
        );
    }

    const runs = bandRuns(trend);
    const bucketsPresent = Array.from(new Set(runs.map((r) => r.bucket)));
    const latest = trend[trend.length - 1];

    return (
        <div className={className}>
            <div
                role="img"
                aria-label={`Fitness over ${trend.length} days, now at ${latest.ctl.toFixed(1)}.`}
                className="h-[168px]"
            >
                <Suspense
                    fallback={<Skeleton className="h-full w-full rounded-lg" />}
                >
                    <Line
                        data={data}
                        options={options}
                        plugins={chartPlugins}
                    />
                </Suspense>
            </div>
            <div
                className="mt-1 flex h-2.5 gap-px overflow-hidden rounded-full"
                aria-hidden
            >
                {runs.map((run) => (
                    <span
                        key={run.startDate}
                        style={{
                            flexGrow: run.length,
                            backgroundColor: BAND_COLOR[run.bucket],
                            opacity: run.bucket === 'balanced' ? 0.35 : 0.7,
                        }}
                    />
                ))}
            </div>
            <div className="mt-2.5 flex flex-wrap items-end justify-between gap-3">
                <p className="max-w-[34ch] text-xs leading-relaxed text-text-2">
                    the line is fitness. the strip under it is how you were
                    holding up.
                </p>
                <div className="flex flex-wrap gap-3 text-label-micro text-text-2">
                    {bucketsPresent.map((bucket) => (
                        <span
                            key={bucket}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                aria-hidden
                                className="size-2 rounded-full"
                                style={{ backgroundColor: BAND_COLOR[bucket] }}
                            />
                            {BAND_LABEL[bucket]}
                        </span>
                    ))}
                </div>
            </div>
        </div>
    );
}
