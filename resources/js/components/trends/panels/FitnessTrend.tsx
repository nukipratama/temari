import type { Chart, ChartEvent, Plugin } from 'chart.js';

import { motion } from 'framer-motion';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Skeleton from '@/components/ui/Skeleton';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { useIsChartDark } from '@/hooks/useIsChartDark';
import { CHART_GROUND, PALETTE } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { fadeInUp, pressShrink, staggerContainer } from '@/lib/motion';
import { formatNaiveIdDate } from '@/lib/pace';
import { badgeEmblem, badgeName, BADGE_ABILITY } from '@/lib/runcard';

import type { TrendRange } from '../RangeToggle';

// Chart.js core + its scale/element registration live inside this lazy
// module, mirroring CtlTrendChart/ProgressionChart so nothing chart-related
// enters this page's own chunk either.
const Line = lazy(() => import('@/components/collection/LineChart'));

export interface FitnessTrendPoint {
    date: string;
    atl: number;
    ctl: number;
}

export interface BadgeMilestone {
    key: string;
    date: string;
}

interface MarkedMilestone extends BadgeMilestone {
    index: number;
}

interface FitnessTrendProps {
    trend: ReadonlyArray<FitnessTrendPoint>;
    milestones: ReadonlyArray<BadgeMilestone>;
    range: TrendRange;
    className?: string;
}

const RANGE_DAYS: Record<TrendRange, number> = {
    '30d': 30,
    '90d': 90,
    '12mo': 365,
};

const CTL_FILL = `${PALETTE.horizon}2e`; // 0.18 alpha

function fitnessHint(ctl: number): string {
    if (ctl < 25) return 'Still building';
    if (ctl < 50) return 'Trending up';
    if (ctl < 80) return 'Stable';
    return 'High';
}

function fatigueHint(atl: number): string {
    if (atl < 25) return 'Fresh';
    if (atl < 55) return 'Normal';
    if (atl < 85) return 'Tired';
    return 'Heavy';
}

/**
 * Fitness/Fatigue panel, plus badges plotted as milestones on the timeline —
 * the badge-earned dates come from RunCard::firstEarnedDatesForUser(), first
 * occurrence only. react-chartjs-2 registers `plugins` once at chart
 * creation, so the plugin reads live values through refs and an effect asks
 * the chart to repaint explicitly on selection/range change, rather than
 * closing over state that would go stale.
 */
export default function FitnessTrend({
    trend,
    milestones,
    range,
    className,
}: Readonly<FitnessTrendProps>) {
    const [selected, setSelected] = useState<string | null>(null);
    const isDark = useIsChartDark();
    const ground = isDark ? CHART_GROUND.dark : CHART_GROUND.light;

    const windowed = useMemo(
        () => trend.slice(-RANGE_DAYS[range]),
        [trend, range],
    );

    const marks = useMemo<MarkedMilestone[]>(() => {
        const index = new Map(windowed.map((p, i) => [p.date, i]));
        return milestones
            .filter((m) => index.has(m.date))
            .map((m) => ({ ...m, index: index.get(m.date)! }));
    }, [windowed, milestones]);

    const active = marks.find((m) => m.key === selected) ?? null;

    const marksRef = useRef(marks);
    const selectedRef = useRef(selected);
    const groundRef = useRef(ground);
    marksRef.current = marks;
    selectedRef.current = selected;
    groundRef.current = ground;

    const chartRef = useRef<Chart<'line'> | null>(null);
    useEffect(() => {
        chartRef.current?.update('none');
    }, [marks, selected, ground]);

    // Badges earned days apart land on the same pixel on a phone at 12
    // months, so markers within a marker's width of each other collapse
    // into one that carries a count. The chip list below stays the precise
    // control for reaching any individual badge.
    const cluster = useCallback((xFor: (index: number) => number) => {
        const groups: Array<{ x: number; members: MarkedMilestone[] }> = [];
        for (const mark of marksRef.current) {
            const x = xFor(mark.index);
            const last = groups.at(-1);
            if (last && x - last.x < 22) last.members.push(mark);
            else groups.push({ x, members: [mark] });
        }
        return groups;
    }, []);

    const milestonePlugin = useMemo<Plugin<'line'>>(
        () => ({
            id: 'milestones',
            afterDatasetsDraw(chart) {
                const { ctx, chartArea, scales: s } = chart;
                if (marksRef.current.length === 0) return;
                ctx.save();
                for (const group of cluster((i) => s.x.getPixelForValue(i))) {
                    const { x, members } = group;
                    const isActive = members.some(
                        (m) => m.key === selectedRef.current,
                    );
                    const g = groundRef.current;
                    ctx.beginPath();
                    ctx.setLineDash(isActive ? [] : [2, 4]);
                    ctx.strokeStyle = isActive
                        ? g.line
                        : `${g.secondaryLine}59`;
                    ctx.lineWidth = 1;
                    ctx.moveTo(x, chartArea.top + 20);
                    ctx.lineTo(x, chartArea.bottom);
                    ctx.stroke();

                    ctx.setLineDash([]);
                    ctx.beginPath();
                    ctx.arc(
                        x,
                        chartArea.top + 10,
                        isActive ? 11 : 9,
                        0,
                        Math.PI * 2,
                    );
                    ctx.fillStyle = isActive ? PALETTE.horizon : g.pointBorder;
                    ctx.fill();
                    ctx.lineWidth = isActive ? 2 : 1;
                    ctx.strokeStyle = isActive ? g.line : g.border;
                    ctx.stroke();

                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    if (members.length === 1) {
                        ctx.font = `${isActive ? 13 : 11}px system-ui`;
                        ctx.fillText(
                            badgeEmblem(members[0].key),
                            x,
                            chartArea.top + 11,
                        );
                    } else {
                        ctx.font = '600 11px system-ui';
                        ctx.fillStyle = g.tick;
                        ctx.fillText(
                            `${members.length}`,
                            x,
                            chartArea.top + 11,
                        );
                    }
                }
                ctx.restore();
            },
        }),
        [cluster],
    );

    const labels = useMemo(
        () => windowed.map((p) => formatNaiveIdDate(p.date, 'short')),
        [windowed],
    );

    const data = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'Fitness',
                    data: windowed.map((p) => p.ctl),
                    borderColor: ground.line,
                    backgroundColor: CTL_FILL,
                    borderWidth: 2.5,
                    pointRadius: 0,
                    tension: 0.3,
                    fill: true,
                },
                {
                    label: 'Fatigue',
                    data: windowed.map((p) => p.atl),
                    borderColor: ground.secondaryLine,
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    borderDash: [4, 4],
                    pointRadius: 0,
                    tension: 0.3,
                    fill: false,
                },
            ],
        }),
        [windowed, labels, ground.line, ground.secondaryLine],
    );

    const options = useMemo(
        () => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 900, easing: 'easeOutQuart' as const },
            layout: { padding: { top: 24 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items: Array<{ dataIndex: number }>) => {
                            const i = items[0]?.dataIndex ?? 0;
                            const row = windowed[i];
                            const dateLabel = row
                                ? formatNaiveIdDate(row.date, 'short')
                                : '';
                            const mark = marks.find(
                                (m) => m.date === row?.date,
                            );
                            return mark
                                ? `${dateLabel} · ${badgeEmblem(mark.key)} ${badgeName(mark.key)}`
                                : dateLabel;
                        },
                    },
                },
            },
            onClick: (_e: ChartEvent, _els: unknown, chart: Chart) => {
                const px = chart.tooltip?.caretX;
                if (px == null) return;
                const near = cluster((i) => chart.scales.x.getPixelForValue(i))
                    .map((g) => ({ g, d: Math.abs(g.x - px) }))
                    .sort((a, b) => a.d - b.d)[0];
                if (!near || near.d >= 18) return;
                // Repeat taps step through a cluster rather than sticking on
                // its first badge.
                setSelected((cur) => {
                    const keys = near.g.members.map((m) => m.key);
                    const at = cur === null ? -1 : keys.indexOf(cur);
                    return at === keys.length - 1 ? null : keys[at + 1];
                });
            },
            scales: {
                x: { grid: { display: false }, ticks: { display: false } },
                y: {
                    grid: { color: ground.grid },
                    ticks: { color: ground.tick, font: { size: 12 } },
                },
            },
        }),
        [windowed, marks, cluster, ground],
    );

    const latest = windowed[windowed.length - 1];
    const ctlCount = useCountUp(latest?.ctl ?? 0);
    const atlCount = useCountUp(latest?.atl ?? 0);
    const formCount = useCountUp((latest?.ctl ?? 0) - (latest?.atl ?? 0));

    if (windowed.length === 0) {
        return (
            <EmptyPanel
                title="Not enough training history yet to draw a trend."
                className={cn('rounded-(--radius-panel)', className)}
            />
        );
    }

    const summarySentence = `Fitness ${windowed[0].ctl.toFixed(0)} to ${latest.ctl.toFixed(0)} over ${windowed.length} days, fatigue now ${latest.atl.toFixed(0)}.`;

    return (
        <div
            className={cn(
                'flex flex-col gap-4 rounded-(--radius-panel) border border-border bg-card p-6 shadow-(--shadow-panel)',
                className,
            )}
        >
            <div>
                <p className="text-label-micro text-text-3">Load</p>
                <h2 className="mt-1 font-serif text-lg text-foreground">
                    Fitness and Fatigue
                </h2>
                <p className="mt-1 text-sm text-text-2">
                    Fitness is your training load averaged over a long window,
                    fatigue over a short one. When the fitness line climbs and
                    the fatigue line sits under it, the work is sticking.
                </p>
            </div>

            <motion.div
                initial="hidden"
                animate="visible"
                variants={staggerContainer}
                className="grid grid-cols-3 gap-2"
            >
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Fitness"
                        value={Math.round(ctlCount)}
                        unit="CTL"
                        sub={fitnessHint(latest.ctl)}
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Fatigue"
                        value={Math.round(atlCount)}
                        unit="ATL"
                        sub={fatigueHint(latest.atl)}
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <StatTile
                        tone="sunken"
                        size="sm"
                        label="Form"
                        value={
                            formCount >= 0
                                ? `+${Math.round(formCount)}`
                                : Math.round(formCount)
                        }
                        sub={
                            latest.ctl - latest.atl >= 0
                                ? 'Rested'
                                : 'Carrying load'
                        }
                    />
                </motion.div>
            </motion.div>

            <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-text-3">
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden
                        className="h-0.5 w-6 rounded-full"
                        style={{ background: ground.line }}
                    />
                    Fitness
                </span>
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden
                        className="h-0 w-6 border-t-2 border-dashed"
                        style={{ borderColor: ground.secondaryLine }}
                    />
                    Fatigue
                </span>
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden
                        className="grid size-4 place-items-center rounded-full border border-border bg-popover text-[9px]"
                    >
                        🏅
                    </span>
                    Badge earned
                </span>
            </div>

            <motion.div
                role="img"
                aria-label={`Fitness and fatigue over ${windowed.length} days. ${summarySentence}`}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4 }}
                className="h-[220px]"
            >
                <span className="sr-only">{summarySentence}</span>
                <Suspense
                    fallback={<Skeleton className="h-full w-full rounded-xl" />}
                >
                    <Line
                        ref={chartRef}
                        data={data}
                        options={options}
                        plugins={[milestonePlugin]}
                    />
                </Suspense>
            </motion.div>

            <div className="flex flex-col gap-3">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className="text-sm font-semibold text-foreground">
                        Milestones on this stretch
                    </h3>
                    <span className="text-xs text-text-3">
                        {marks.length} badges
                    </span>
                </div>

                {marks.length === 0 ? (
                    <p className="text-sm text-text-3">
                        No badges landed in this window. Widen the range to see
                        the rest of the year.
                    </p>
                ) : (
                    <ul className="flex flex-wrap gap-2">
                        {marks.map((mark) => (
                            <li key={mark.key} className="shrink-0">
                                <motion.button
                                    type="button"
                                    whileTap={pressShrink}
                                    aria-pressed={mark.key === selected}
                                    onClick={() =>
                                        setSelected((cur) =>
                                            cur === mark.key ? null : mark.key,
                                        )
                                    }
                                    className={cn(
                                        'flex items-center gap-2 rounded-full border px-3 py-2 text-xs whitespace-nowrap transition-colors',
                                        mark.key === selected
                                            ? 'border-horizon-ink bg-horizon/25 text-foreground'
                                            : 'border-border bg-popover text-text-2 hover:bg-cream-deep',
                                    )}
                                >
                                    <span aria-hidden>
                                        {badgeEmblem(mark.key)}
                                    </span>
                                    <span className="font-semibold">
                                        {badgeName(mark.key)}
                                    </span>
                                    <span className="text-text-3">
                                        {formatNaiveIdDate(mark.date, 'short')}
                                    </span>
                                </motion.button>
                            </li>
                        ))}
                    </ul>
                )}

                {active ? (
                    <div className="rounded-(--radius-panel) border border-horizon-ink/30 bg-horizon/12 p-4">
                        <p className="text-sm font-semibold text-foreground">
                            {badgeEmblem(active.key)} {badgeName(active.key)}
                            <span className="ml-2 font-normal text-text-3">
                                {formatNaiveIdDate(active.date, 'short')}
                            </span>
                        </p>
                        <p className="mt-1 text-sm text-text-2">
                            {BADGE_ABILITY[active.key]}
                        </p>
                    </div>
                ) : (
                    <p className="text-xs text-text-3">
                        Pick a badge to mark it on the line, or tap a marker on
                        the chart.
                    </p>
                )}
            </div>
        </div>
    );
}
