import type { ActiveElement, Chart, ChartEvent, Plugin } from 'chart.js';

import { Suspense, useMemo, useState } from 'react';

import type { FormStatus } from '@/types/inertia';

import Skeleton from '@/components/ui/Skeleton';
import { StatDelta } from '@/components/ui/StatTile';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useIsDarkGround } from '@/hooks/useIsDarkGround';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import { CHART_GROUND, PALETTE } from '@/lib/chartTokens';
import { lazyIsland } from '@/lib/lazyIsland';
import { ID_MONTH_SHORT, formatNaiveMonthDayId } from '@/lib/pace';

// Chart.js core + its scale/element registration live inside this lazy
// module, mirroring CtlTrendChart/ProgressionChart so nothing chart-related
// enters this page's own chunk either.
const Line = lazyIsland(() => import('@/components/collection/LineChart'));

export interface FitnessTrendPoint {
    date: string;
    atl: number;
    ctl: number;
    /** TrainingLoad::formStatus() for this day, stamped server-side; null during the 42-day warm-up. */
    form_status: FormStatus | null;
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

type BandBucket = 'fresh' | 'steady' | 'heavy' | 'learning';

const BAND_BUCKET: Record<FormStatus, BandBucket> = {
    fresh: 'fresh',
    optimal: 'steady',
    fatigued: 'heavy',
    overreaching: 'heavy',
};

const BAND_COLOR: Record<BandBucket, string> = {
    fresh: PALETTE.leaf,
    steady: PALETTE.stone,
    heavy: PALETTE.ember,
    learning: PALETTE.line,
};

const BAND_LABEL: Record<BandBucket, string> = {
    fresh: 'fresh',
    steady: 'steady',
    heavy: 'heavy',
    learning: 'still learning your load',
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
            point.form_status === null
                ? 'learning'
                : BAND_BUCKET[point.form_status];
        const last = runs[runs.length - 1];
        if (last && last.bucket === bucket) {
            last.length += 1;
        } else {
            runs.push({ bucket, length: 1, startDate: point.date });
        }
    }
    return runs;
}

interface FitnessOverlayOptions {
    deloadIndices: number[];
    deloadColor: string;
    highlightDays: number;
    total: number;
    highlightColor: string;
    cursorIndex: number | null;
    cursorColor: string;
}

function overlayOf(chart: Chart): FitnessOverlayOptions | undefined {
    return (chart.options.plugins as { fitnessOverlay?: FitnessOverlayOptions })
        .fitnessOverlay;
}

function strokeVerticalAt(
    chart: Chart,
    index: number,
    color: string,
    lineWidth: number,
    dash: number[] = [],
): void {
    const { ctx, chartArea, scales } = chart;
    const x = scales.x.getPixelForValue(index);
    ctx.save();
    ctx.strokeStyle = color;
    ctx.lineWidth = lineWidth;
    ctx.setLineDash(dash);
    ctx.beginPath();
    ctx.moveTo(x, chartArea.top);
    ctx.lineTo(x, chartArea.bottom);
    ctx.stroke();
    ctx.restore();
}

/**
 * Draws from `options.plugins.fitnessOverlay`: beneath the line, the trailing
 * `highlightDays` shaded so "a month ago" is a place on the line (skipped once
 * the window is no wider than the shade); above it, a dashed line at each
 * deload-week index and the scrub cursor the readout points at.
 */
const fitnessOverlayPlugin: Plugin<'line'> = {
    id: 'fitnessOverlay',
    beforeDatasetsDraw(chart) {
        const overlay = overlayOf(chart);
        if (!overlay) return;
        const { highlightDays, total, highlightColor } = overlay;
        if (highlightDays <= 0 || total === 0 || highlightDays >= total) {
            return;
        }

        const { ctx, chartArea } = chart;
        const x0 = chart.scales.x.getPixelForValue(
            Math.max(0, total - highlightDays),
        );
        ctx.save();
        ctx.fillStyle = highlightColor;
        ctx.fillRect(
            x0,
            chartArea.top,
            chartArea.right - x0,
            chartArea.bottom - chartArea.top,
        );
        ctx.restore();
    },
    afterDatasetsDraw(chart) {
        const overlay = overlayOf(chart);
        if (!overlay) return;

        overlay.deloadIndices.forEach((index) =>
            strokeVerticalAt(chart, index, overlay.deloadColor, 1, [3, 2]),
        );
        if (overlay.cursorIndex !== null) {
            strokeVerticalAt(
                chart,
                overlay.cursorIndex,
                overlay.cursorColor,
                1.5,
            );
        }
    },
};

const CHART_PLUGINS = [fitnessOverlayPlugin];

type RangeKey = '1M' | '3M' | '1Y';

const RANGE_DAYS: Record<RangeKey, number> = { '1M': 30, '3M': 90, '1Y': 365 };
const RANGE_KEYS: RangeKey[] = ['1M', '3M', '1Y'];

/** "jul" — the short month a date falls in, for the headline's "since jul". */
function monthShort(iso: string): string {
    const month = Number(iso.slice(5, 7)) - 1;
    return ID_MONTH_SHORT[month] ?? '';
}

/**
 * The long-term load line "vs a month ago" owns: one CTL series with a categorical
 * load balance band beneath it, a range chip (1M/3M/1Y, default 3M) choosing how
 * much of the 365-day history is drawn, a headline reading the value now and
 * the change since the range's start, and a scrub cursor (hover or touch
 * drag) that swaps the headline for a per-day readout. No ATL line, no stat
 * tiles, no badge chips — those live in the comparison cards around it, or
 * off the page entirely (#967, #1296).
 */
export default function FitnessPanel({
    trend,
    annotations = NO_ANNOTATIONS,
    highlightDays = 30,
    className,
}: Readonly<FitnessPanelProps>) {
    const isDark = useIsDarkGround();
    const ground = isDark ? CHART_GROUND.dark : CHART_GROUND.light;
    const reducedMotion = useReducedMotion();

    const [range, setRange] = useState<RangeKey>('3M');
    const [cursorIndex, setCursorIndex] = useState<number | null>(null);

    const visible = useMemo(
        () => trend.slice(-RANGE_DAYS[range]),
        [trend, range],
    );

    const deloadIndices = useMemo(() => {
        const deload = new Set(annotations.deload);
        const indices: number[] = [];
        visible.forEach((point, index) => {
            if (deload.has(point.date)) indices.push(index);
        });
        return indices;
    }, [visible, annotations]);

    const labels = useMemo(
        () => visible.map((p) => formatNaiveMonthDayId(p.date)),
        [visible],
    );

    const data = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'long-term load',
                    data: visible.map((p) => p.ctl),
                    borderColor: ground.line,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.25,
                    fill: false,
                },
            ],
        }),
        [visible, labels, ground.line],
    );

    const options = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion
                ? (false as const)
                : { duration: 900, easing: 'easeOutQuart' as const },
            interaction: { mode: 'index' as const, intersect: false },
            onHover: (
                _event: ChartEvent,
                elements: ActiveElement[],
                chart: Chart,
            ): void => {
                const overlay = overlayOf(chart);
                const index = elements.length > 0 ? elements[0].index : null;
                if (!overlay || overlay.cursorIndex === index) return;
                overlay.cursorIndex = index;
                chart.draw();
                setCursorIndex(index);
            },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
                fitnessOverlay: {
                    deloadIndices,
                    deloadColor: PALETTE.stone,
                    highlightDays,
                    total: visible.length,
                    highlightColor: `${PALETTE.horizon}22`,
                    cursorIndex: null,
                    cursorColor: ground.border,
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
        [ground, reducedMotion, deloadIndices, highlightDays, visible.length],
    );

    const runs = useMemo(() => bandRuns(visible), [visible]);
    const bucketsPresent = useMemo(
        () => Array.from(new Set(runs.map((r) => r.bucket))),
        [runs],
    );

    if (trend.length === 0) {
        return (
            <p className={className ?? 'text-sm text-text-2'}>
                not enough training history yet to draw a trend.
            </p>
        );
    }

    const latest = visible[visible.length - 1];
    const rangeStart = visible[0];
    const activePoint = cursorIndex !== null ? visible[cursorIndex] : null;
    const delta = rangeStart ? latest.ctl - rangeStart.ctl : null;

    return (
        <div className={className}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                {activePoint ? (
                    <p className="font-mono text-sm font-semibold tabular-nums text-foreground">
                        {formatNaiveMonthDayId(activePoint.date)} · long-term
                        load {Math.round(activePoint.ctl)}
                    </p>
                ) : (
                    <p className="flex items-baseline gap-2 font-mono tabular-nums text-foreground">
                        <span className="text-stat-sm font-bold">
                            {Math.round(latest.ctl)}
                        </span>
                        {delta !== null && (
                            <StatDelta value={Math.round(delta)} decimals={0} />
                        )}
                        {rangeStart && (
                            <span className="text-xs font-normal text-text-3">
                                since {monthShort(rangeStart.date)}
                            </span>
                        )}
                    </p>
                )}
                <ToggleGroup
                    value={range}
                    onValueChange={(key) => {
                        setRange(key);
                        setCursorIndex(null);
                    }}
                    aria-label="chart range"
                >
                    {RANGE_KEYS.map((key) => (
                        <ToggleGroupItem key={key} value={key}>
                            {key}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </div>
            <div
                role="img"
                aria-label={`Long-term load over ${visible.length} days, now at ${latest.ctl.toFixed(1)}.`}
                className="mt-2 h-[10.5rem]"
                style={{ touchAction: 'pan-y' }}
                data-no-pull-refresh
            >
                <Suspense
                    fallback={<Skeleton className="h-full w-full rounded-lg" />}
                >
                    <Line
                        data={data}
                        options={options}
                        plugins={CHART_PLUGINS}
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
                            opacity: run.bucket === 'steady' ? 0.35 : 0.7,
                        }}
                    />
                ))}
            </div>
            <div className="mt-2.5 flex flex-wrap items-end justify-between gap-3">
                <p className="max-w-[34ch] text-xs leading-relaxed text-text-2">
                    the line is your long-term load. the strip under it shows
                    your load balance along the way.
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
