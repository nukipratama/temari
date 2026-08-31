import { motion } from 'framer-motion';
import { lazy, Suspense, useMemo, useState } from 'react';

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
 * Fitness/Fatigue panel, with the badges earned in the window as chips beneath
 * it — the badge-earned dates come from RunCard::firstEarnedDatesForUser(),
 * first occurrence only.
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

    const marks = useMemo<BadgeMilestone[]>(() => {
        const dates = new Set(windowed.map((p) => p.date));
        return milestones.filter((m) => dates.has(m.date));
    }, [windowed, milestones]);

    const active = marks.find((m) => m.key === selected) ?? null;

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
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { display: false } },
                y: {
                    grid: { color: ground.grid },
                    ticks: { color: ground.tick, font: { size: 12 } },
                },
            },
        }),
        [ground],
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
                    <Line data={data} options={options} />
                </Suspense>
            </motion.div>

            <div className="flex flex-col gap-3">
                {marks.length > 0 && (
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

                {active !== null && (
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
                )}
            </div>
        </div>
    );
}
