import { motion } from 'framer-motion';
import { lazy, Suspense, useMemo } from 'react';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Skeleton from '@/components/ui/Skeleton';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import { PALETTE } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { countUpEase } from '@/lib/motion';
import { formatDurationHMS, formatNaiveIdDate } from '@/lib/pace';

// Chart.js core + its scale/element registration live inside this lazy module,
// so nothing chart-related enters ProgressionChart's own chunk.
const Line = lazy(() => import('./LineChart'));

interface ProgressionChartProps {
    weeks: ReadonlyArray<string>;
    timesSec: ReadonlyArray<number | null>;
    goalSec: number | null;
    /** Category name shown in the chart's accessible label, e.g. "5K". */
    category?: string;
    className?: string;
}

const HORIZON_FILL_FLAT = `${PALETTE.horizon}2e`; // 0.18 alpha
const HORIZON_FILL_TOP = `${PALETTE.horizon}52`; // 0.32 alpha
const HORIZON_FILL_BOTTOM = `${PALETTE.horizon}05`; // 0.02 alpha
const GRID_LINE = `${PALETTE.ink3}1f`; // 0.12 alpha

function lastDefinedIndex(values: ReadonlyArray<number | null>): number {
    for (let i = values.length - 1; i >= 0; i--) {
        if (values[i] != null) return i;
    }
    return -1;
}

export default function ProgressionChart({
    weeks,
    timesSec,
    goalSec,
    category,
    className,
}: Readonly<ProgressionChartProps>) {
    const reducedMotion = useReducedMotion();
    const chartLabel = category
        ? `Best time progression chart ${category}`
        : 'Best time progression chart';
    const firstIdx = timesSec.findIndex((t) => t != null);
    const lastIdx = lastDefinedIndex(timesSec);
    const summarySentence =
        firstIdx >= 0 && lastIdx >= 0
            ? `From ${formatDurationHMS(timesSec[firstIdx]!)} on ${formatNaiveIdDate(weeks[firstIdx], 'short')} to ${formatDurationHMS(timesSec[lastIdx]!)} on ${formatNaiveIdDate(weeks[lastIdx], 'short')}.`
            : 'No time data for this period yet.';
    // Space points by their real date (day-offset from the first week), not at even
    // intervals, so uneven time gaps read honestly instead of overstating progress.
    const xOffsets = useMemo(() => {
        const baseMs = weeks.length > 0 ? Date.parse(weeks[0]) : 0;
        return weeks.map((w) => (Date.parse(w) - baseMs) / 86_400_000);
    }, [weeks]);

    const data = useMemo(
        () => ({
            labels: weeks.map((w) => formatNaiveIdDate(w, 'short')),
            datasets: [
                {
                    label: 'Best time',
                    data: timesSec.map((t, i) =>
                        t == null ? null : { x: xOffsets[i], y: t / 60 },
                    ),
                    borderColor: PALETTE.horizon,
                    // Vertical gradient area fill (denser near the line, fading to the axis)
                    // instead of a flat wash, so the chart reads as intentional, not a default.
                    backgroundColor: (ctx: {
                        chart: {
                            chartArea?: { top: number; bottom: number };
                            ctx: CanvasRenderingContext2D;
                        };
                    }) => {
                        const { chartArea, ctx: canvasCtx } = ctx.chart;
                        if (!chartArea) return HORIZON_FILL_FLAT;
                        const g = canvasCtx.createLinearGradient(
                            0,
                            chartArea.top,
                            0,
                            chartArea.bottom,
                        );
                        g.addColorStop(0, HORIZON_FILL_TOP);
                        g.addColorStop(1, HORIZON_FILL_BOTTOM);
                        return g;
                    },
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: PALETTE.horizonDeep,
                    pointBorderColor: PALETTE.cream,
                    pointBorderWidth: 1.5,
                    tension: 0.32,
                    fill: true,
                    spanGaps: true,
                },
                ...(goalSec
                    ? [
                          {
                              label: 'Goal',
                              // Flat line spanning the full time range (2 points, not one
                              // per week) so its x still aligns with the time-scaled axis.
                              data:
                                  xOffsets.length > 0
                                      ? [
                                            { x: xOffsets[0], y: goalSec / 60 },
                                            {
                                                x: xOffsets.at(-1)!,
                                                y: goalSec / 60,
                                            },
                                        ]
                                      : [],
                              borderColor: PALETTE.citrus,
                              backgroundColor: 'transparent',
                              borderDash: [6, 6],
                              borderWidth: 1.5,
                              pointRadius: 0,
                              tension: 0,
                              fill: false,
                          },
                      ]
                    : []),
            ],
        }),
        [weeks, timesSec, goalSec, xOffsets],
    );

    const options = useMemo(() => {
        const xMin = xOffsets.length > 0 ? xOffsets[0] : 0;
        const lastX = xOffsets.length > 0 ? xOffsets.at(-1)! : 0;
        const xMax = lastX > xMin ? lastX : xMin + 1;
        const animation: false | { duration: number; easing: 'easeOutQuart' } =
            reducedMotion ? false : { duration: 800, easing: 'easeOutQuart' };
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items: Array<{ dataIndex: number }>) => {
                            const i = items[0]?.dataIndex ?? 0;
                            return weeks[i]
                                ? formatNaiveIdDate(weeks[i], 'short')
                                : '';
                        },
                        label: (ctx: {
                            dataset: { label?: string };
                            parsed: { y: number | null };
                        }) => {
                            const v = ctx.parsed.y;
                            if (v == null) return '';
                            return `${ctx.dataset.label}: ${formatDurationHMS(Math.round(v * 60))}`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    type: 'linear' as const,
                    min: xMin,
                    max: xMax,
                    grid: { display: false },
                    // Date labels collide on narrow phones; the date lives in the tooltip instead.
                    ticks: { display: false },
                },
                y: {
                    reverse: true,
                    grid: { color: GRID_LINE },
                    ticks: {
                        color: PALETTE.ink2,
                        font: { size: 12 },
                        callback: (val: number | string) => {
                            const v =
                                typeof val === 'number' ? val : Number(val);
                            return Number.isFinite(v)
                                ? formatDurationHMS(Math.round(v * 60))
                                : String(val);
                        },
                    },
                },
            },
        };
    }, [weeks, xOffsets, reducedMotion]);

    if (weeks.length === 0) {
        return (
            <EmptyPanel
                title="Not enough runs at this distance yet to draw a progression line."
                className={cn('py-10', className)}
            />
        );
    }

    return (
        <motion.div
            role="img"
            aria-label={`${chartLabel}. ${summarySentence}`}
            className={cn('h-[260px] sm:h-[300px]', className)}
            initial={{ opacity: 0, scaleY: 0.92 }}
            animate={{ opacity: 1, scaleY: 1 }}
            transition={{ duration: 0.6, ease: countUpEase }}
            style={{ transformOrigin: 'bottom' }}
        >
            <span className="sr-only">{summarySentence}</span>
            <Suspense
                fallback={<Skeleton className="h-full w-full rounded-md" />}
            >
                <Line data={data} options={options} />
            </Suspense>
        </motion.div>
    );
}
