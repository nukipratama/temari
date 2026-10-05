import type { Chart, Plugin } from 'chart.js';

import { Suspense, useMemo } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';
import Skeleton from '@/components/ui/Skeleton';
import { useIsDarkGround } from '@/hooks/useIsDarkGround';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import { CHART_GROUND, PALETTE } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { lazyIsland } from '@/lib/lazyIsland';
import { formatDurationHMS, formatNaiveMonthDayId } from '@/lib/pace';
import { raceDistanceLabel } from '@/lib/raceGoal';

const Line = lazyIsland(() => import('@/components/collection/LineChart'));

const NO_POINTS: ReadonlyArray<SupportedHistoryPoint> = [];

export interface SupportedHistoryPoint {
    date: string;
    supported_time_sec: number;
    /** The effort the supported time rests on that day. */
    source: { distance_m: number; date: string } | null;
    /** True when this day's effort differs from the day before's. */
    new_source: boolean;
}

export interface SupportedHistory {
    target_time_sec: number;
    /** Daily snapshots for the current race within its season, oldest first. */
    points: ReadonlyArray<SupportedHistoryPoint>;
}

interface SupportedOverTimeProps {
    history: SupportedHistory | null;
    className?: string;
}

export interface StepLabel {
    index: number;
    text: string;
}

/** "10K · oct 1" at each day whose source effort changed. */
export function stepLabels(
    points: ReadonlyArray<SupportedHistoryPoint>,
): StepLabel[] {
    return points.flatMap((point, index) =>
        point.new_source && point.source !== null
            ? [
                  {
                      index,
                      text: `${raceDistanceLabel(point.source.distance_m)} · ${formatNaiveMonthDayId(point.source.date)}`,
                  },
              ]
            : [],
    );
}

/** "3:15 faster since aug 3", "… slower since …", or "no change since …". */
export function changeLine(points: ReadonlyArray<SupportedHistoryPoint>): {
    text: string;
    tone: 'faster' | 'slower' | 'same';
} {
    const first = points[0];
    const latest = points[points.length - 1];
    const since = formatNaiveMonthDayId(first.date);
    const gain = first.supported_time_sec - latest.supported_time_sec;

    if (gain > 0) {
        return {
            text: `${formatDurationHMS(gain)} faster since ${since}`,
            tone: 'faster',
        };
    }
    if (gain < 0) {
        return {
            text: `${formatDurationHMS(-gain)} slower since ${since}`,
            tone: 'slower',
        };
    }

    return { text: `no change since ${since}`, tone: 'same' };
}

const TONE_CLASS = {
    faster: 'text-leaf-ink',
    slower: 'text-ember-ink',
    same: 'text-text-3',
} as const;

interface SupportedLabelsOptions {
    steps: StepLabel[];
    stepColor: string;
    targetText: string;
    targetColor: string;
}

function labelsOf(chart: Chart): SupportedLabelsOptions | undefined {
    return (
        chart.options.plugins as { supportedLabels?: SupportedLabelsOptions }
    ).supportedLabels;
}

const LABEL_HEIGHT = 12;

/**
 * Draws "your target m:ss" over the dashed target and each step's effort
 * above its point, or below and to the right where it would cross the target.
 */
const supportedLabelsPlugin: Plugin<'line'> = {
    id: 'supportedLabels',
    afterDatasetsDraw(chart) {
        const labels = labelsOf(chart);
        if (!labels) return;

        const { ctx, chartArea, scales } = chart;
        const family = getComputedStyle(chart.canvas).fontFamily;
        const supported = chart.data.datasets[0]?.data as number[];
        const targetY = scales.y.getPixelForValue(
            chart.data.datasets[1]?.data[0] as number,
        );

        ctx.save();
        ctx.font = `10px ${family}`;
        ctx.textBaseline = 'bottom';
        ctx.fillStyle = labels.targetColor;
        ctx.textAlign = 'left';
        ctx.fillText(labels.targetText, chartArea.left + 2, targetY - 3);

        ctx.font = `600 10px ${family}`;
        ctx.fillStyle = labels.stepColor;
        for (const step of labels.steps) {
            const x = scales.x.getPixelForValue(step.index);
            const y = scales.y.getPixelForValue(supported[step.index]);
            const width = ctx.measureText(step.text).width;
            const above = y - 4;
            const crossesTarget =
                targetY > above - LABEL_HEIGHT - 2 && targetY < above + 2;

            if (crossesTarget) {
                ctx.textBaseline = 'top';
                ctx.textAlign =
                    x + 4 + width > chartArea.right ? 'right' : 'left';
                ctx.fillText(
                    step.text,
                    ctx.textAlign === 'left' ? x + 4 : x - 4,
                    y + 4,
                );
                continue;
            }

            ctx.textBaseline = 'bottom';
            ctx.textAlign =
                x + width / 2 > chartArea.right
                    ? 'right'
                    : x - width / 2 < chartArea.left
                      ? 'left'
                      : 'center';
            ctx.fillText(step.text, x, above);
        }
        ctx.restore();
    },
};

const TICK_STEPS_SEC = [30, 60, 120, 300, 600, 900, 1800, 3600];

/** A whole-minute (or half-minute) tick step giving at most four ticks across the values. */
export function tickStepSec(values: ReadonlyArray<number>): number {
    const span = Math.max(...values) - Math.min(...values);

    return TICK_STEPS_SEC.find((step) => span / step <= 4) ?? 3600;
}

const CHART_PLUGINS = [supportedLabelsPlugin];

/**
 * "supported over time" follows "vs race day": the supported time at the
 * current race's distance across the season, as a stepped line where faster
 * is up, the target as a dashed reference, and a label wherever the effort it
 * rests on changed. Absent without a race, a supported time, or two days of
 * history.
 */
export default function SupportedOverTime({
    history,
    className,
}: Readonly<SupportedOverTimeProps>) {
    const isDark = useIsDarkGround();
    const ground = isDark ? CHART_GROUND.dark : CHART_GROUND.light;
    const reducedMotion = useReducedMotion();
    const points = history?.points ?? NO_POINTS;
    const target = history?.target_time_sec ?? 0;

    const data = useMemo(
        () => ({
            labels: points.map((p) => formatNaiveMonthDayId(p.date)),
            datasets: [
                {
                    label: 'supported',
                    data: points.map((p) => p.supported_time_sec),
                    borderColor: PALETTE.leaf,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: points.map((p) => (p.new_source ? 3 : 0)),
                    pointBackgroundColor: PALETTE.leaf,
                    pointBorderColor: ground.pointBorder,
                    stepped: true,
                    fill: false,
                },
                {
                    label: 'your target',
                    data: points.map(() => target),
                    borderColor: ground.secondaryLine,
                    borderDash: [4, 4],
                    borderWidth: 1.5,
                    pointRadius: 0,
                    fill: false,
                },
            ],
        }),
        [points, target, ground],
    );

    const steps = useMemo(() => stepLabels(points), [points]);
    const yStep = useMemo(
        () => tickStepSec([...points.map((p) => p.supported_time_sec), target]),
        [points, target],
    );

    const options = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: reducedMotion
                ? (false as const)
                : { duration: 900, easing: 'easeOutQuart' as const },
            layout: { padding: { top: 16 } },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
                supportedLabels: {
                    steps,
                    stepColor: ground.leafInk,
                    targetText: `your target ${formatDurationHMS(target)}`,
                    targetColor: ground.secondaryLine,
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
                        maxTicksLimit: 5,
                    },
                    border: { display: false },
                },
                y: {
                    reverse: true,
                    grace: '10%',
                    grid: { color: ground.grid },
                    ticks: {
                        color: ground.tick,
                        font: { size: 10 },
                        stepSize: yStep,
                        callback: (value: number | string) =>
                            formatDurationHMS(Number(value)),
                    },
                    border: { display: false },
                },
            },
        }),
        [ground, reducedMotion, steps, target, yStep],
    );

    if (history === null || points.length < 2) {
        return null;
    }

    const latest = points[points.length - 1];
    const change = changeLine(points);

    return (
        <section className={className}>
            <Eyebrow as="h2" token="small" tone="ink-2">
                supported over time
            </Eyebrow>
            <p className="mt-3 flex flex-wrap items-baseline gap-x-2 font-mono tabular-nums">
                <span className="text-stat-sm font-bold text-foreground">
                    {formatDurationHMS(latest.supported_time_sec)}
                </span>
                <span className={cn('text-xs', TONE_CLASS[change.tone])}>
                    {change.text}
                </span>
            </p>
            <div
                role="img"
                aria-label={`supported time over ${points.length} days, now ${formatDurationHMS(latest.supported_time_sec)}, against your target of ${formatDurationHMS(target)}.`}
                className="mt-2 h-[9.5rem]"
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
        </section>
    );
}
