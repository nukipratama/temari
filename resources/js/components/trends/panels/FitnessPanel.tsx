import { motion } from 'framer-motion';
import { lazy, Suspense, useMemo, useState } from 'react';

import type { Rarity } from '@/types/inertia';

import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import Skeleton from '@/components/ui/Skeleton';
import { useCountUp } from '@/hooks/useCountUp';
import { useIsChartDark } from '@/hooks/useIsChartDark';
import { CHART_GROUND } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { fadeInUp, pressShrink, staggerContainer } from '@/lib/motion';
import { formatNaiveIdDate } from '@/lib/pace';
import { badgeName, BADGE_ABILITY } from '@/lib/runcard';

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
    rarity: Rarity;
}

export interface StreakSummaryLike {
    weeks: number;
    rest_weeks_held: number;
    rest_weeks_cap: number;
    ran_this_week: boolean;
    week_ends_on: string;
}

interface PanelChip {
    key: string;
    label: string;
    rarity: Rarity;
    detail: string;
}

interface FitnessPanelProps {
    trend: ReadonlyArray<FitnessTrendPoint>;
    milestones: ReadonlyArray<BadgeMilestone>;
    streak: StreakSummaryLike;
    range: TrendRange;
    className?: string;
}

const RANGE_DAYS: Record<TrendRange, number> = {
    '30d': 30,
    '90d': 90,
    '12mo': 365,
};

const RARITY_INK: Record<Rarity, string> = {
    common: 'text-rarity-common-ink',
    uncommon: 'text-rarity-uncommon-ink',
    rare: 'text-rarity-rare-ink',
    epic: 'text-rarity-epic-ink',
    legendary: 'text-rarity-legendary-ink',
};

/** The panel's headline, read off the window the range tabs selected. */
export function fitnessVerdict(
    firstCtl: number,
    lastCtl: number,
    lastAtl: number,
): string {
    const climb = lastCtl - firstCtl;
    if (climb >= 2) {
        return lastCtl - lastAtl >= 0
            ? 'climbing, not spiking.'
            : 'climbing, and carrying the load.';
    }
    if (climb <= -2) {
        return 'easing off.';
    }
    return 'holding steady.';
}

function streakDetail(streak: StreakSummaryLike): string {
    const rest =
        streak.rest_weeks_held > 0
            ? ` ${streak.rest_weeks_held} rest week${streak.rest_weeks_held === 1 ? '' : 's'} in hand to forgive a missed one.`
            : '';
    return `${streak.weeks} consecutive week${streak.weeks === 1 ? '' : 's'} with at least one run logged.${rest}`;
}

function FitnessStat({
    value,
    label,
}: Readonly<{ value: string; label: string }>) {
    return (
        <div className="rounded-lg bg-muted p-2.5 text-center">
            <b className="block font-mono text-base font-extrabold text-foreground tabular-nums">
                {value}
            </b>
            <span className="text-label-micro text-text-2">{label}</span>
        </div>
    );
}

/**
 * The prototype's single fitness panel: the CTL/ATL chart with its stat tiles,
 * its hand-built legend, and the badges earned in the selected window as chips
 * (P14/P15 — every one of them, wrapping). The week streak rides along as a
 * chip of its own, which is the only place it survives (P27).
 */
export default function FitnessPanel({
    trend,
    milestones,
    streak,
    range,
    className,
}: Readonly<FitnessPanelProps>) {
    const [selected, setSelected] = useState<string | null>(null);
    const isDark = useIsChartDark();
    const ground = isDark ? CHART_GROUND.dark : CHART_GROUND.light;

    const windowed = useMemo(
        () => trend.slice(-RANGE_DAYS[range]),
        [trend, range],
    );

    const chips = useMemo<PanelChip[]>(() => {
        const dates = new Set(windowed.map((p) => p.date));
        const earned = milestones
            .filter((m) => dates.has(m.date))
            .map((m) => ({
                key: m.key,
                label: badgeName(m.key),
                rarity: m.rarity,
                detail: `${BADGE_ABILITY[m.key] ?? ''} First earned ${formatNaiveIdDate(m.date, 'short')}.`.trim(),
            }));

        return streak.weeks > 0
            ? [
                  {
                      key: 'week-streak',
                      label: `${streak.weeks}-week streak`,
                      rarity: 'uncommon' as Rarity,
                      detail: streakDetail(streak),
                  },
                  ...earned,
              ]
            : earned;
    }, [windowed, milestones, streak]);

    const active = chips.find((c) => c.key === selected) ?? null;

    const labels = useMemo(
        () => windowed.map((p) => formatNaiveIdDate(p.date, 'short')),
        [windowed],
    );

    const data = useMemo(
        () => ({
            labels,
            datasets: [
                {
                    label: 'fitness',
                    data: windowed.map((p) => p.ctl),
                    borderColor: ground.line,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.35,
                    fill: false,
                },
                {
                    label: 'fatigue',
                    data: windowed.map((p) => p.atl),
                    borderColor: ground.secondaryLine,
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    borderDash: [3, 3],
                    pointRadius: 0,
                    tension: 0.35,
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
            plugins: { legend: { display: false } },
            scales: {
                x: { display: false },
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
        [ground],
    );

    const latest = windowed[windowed.length - 1];
    const ctlCount = useCountUp(latest?.ctl ?? 0);
    const atlCount = useCountUp(latest?.atl ?? 0);
    const formCount = useCountUp((latest?.ctl ?? 0) - (latest?.atl ?? 0));

    if (windowed.length === 0) {
        return (
            <EmptyPanel
                title="not enough training history yet to draw a trend."
                className={className}
            />
        );
    }

    const summarySentence = `Fitness ${windowed[0].ctl.toFixed(0)} to ${latest.ctl.toFixed(0)} over ${windowed.length} days, fatigue now ${latest.atl.toFixed(0)}.`;
    const form = Math.round(formCount);

    return (
        <Card as="section" className={className}>
            <Eyebrow token="micro" className="text-text-2">
                Fitness
            </Eyebrow>
            <h2 className="mt-1 font-serif text-base font-bold text-foreground">
                {fitnessVerdict(windowed[0].ctl, latest.ctl, latest.atl)}
            </h2>
            <p className="mt-1.5 text-xs leading-relaxed text-text-2">
                Fitness (CTL) tracks your rolling training load; fatigue (ATL)
                reacts faster. Form is the gap between them, and positive means
                you&apos;re absorbing the work.
            </p>

            <motion.div
                initial="hidden"
                animate="visible"
                variants={staggerContainer}
                className="mt-3.5 grid grid-cols-3 gap-2"
            >
                <motion.div variants={fadeInUp}>
                    <FitnessStat
                        value={Math.round(ctlCount).toString()}
                        label="fitness"
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <FitnessStat
                        value={Math.round(atlCount).toString()}
                        label="fatigue"
                    />
                </motion.div>
                <motion.div variants={fadeInUp}>
                    <FitnessStat
                        value={form >= 0 ? `+${form}` : form.toString()}
                        label="form"
                    />
                </motion.div>
            </motion.div>

            <motion.div
                role="img"
                aria-label={`Fitness and fatigue over ${windowed.length} days. ${summarySentence}`}
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4 }}
                className="mt-3.5 h-[150px]"
            >
                <span className="sr-only">{summarySentence}</span>
                <Suspense
                    fallback={<Skeleton className="h-full w-full rounded-xl" />}
                >
                    <Line data={data} options={options} />
                </Suspense>
            </motion.div>

            <div className="mt-2.5 flex gap-3.5 text-label-micro text-text-2">
                <span className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden
                        className="h-0.5 w-3 flex-none rounded-full"
                        style={{ background: ground.line }}
                    />
                    Fitness
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden
                        className="h-0 w-3 flex-none border-t-2 border-dashed"
                        style={{ borderColor: ground.secondaryLine }}
                    />
                    Fatigue
                </span>
            </div>

            {chips.length > 0 && (
                <ul className="mt-3.5 flex flex-wrap gap-1.5">
                    {chips.map((chip) => (
                        <li key={chip.key}>
                            <motion.button
                                type="button"
                                whileTap={pressShrink}
                                aria-pressed={chip.key === selected}
                                onClick={() =>
                                    setSelected((cur) =>
                                        cur === chip.key ? null : chip.key,
                                    )
                                }
                                className={cn(
                                    'focus-ring inline-flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-xs font-bold whitespace-nowrap transition-colors',
                                    chip.key === selected
                                        ? 'bg-horizon/25 text-foreground'
                                        : 'bg-muted text-foreground',
                                )}
                            >
                                <Icon
                                    icon="mdi:medal-outline"
                                    className={cn(
                                        'size-3.5',
                                        RARITY_INK[chip.rarity],
                                    )}
                                    aria-hidden
                                />
                                {chip.label}
                            </motion.button>
                        </li>
                    ))}
                </ul>
            )}

            {active !== null && (
                <div className="mt-2.5 rounded-lg bg-muted px-3 py-2.5">
                    <p className="flex items-center gap-1.5 text-sm font-bold text-foreground">
                        <Icon
                            icon="mdi:medal-outline"
                            className={cn(
                                'size-3.5',
                                RARITY_INK[active.rarity],
                            )}
                            aria-hidden
                        />
                        {active.label}
                    </p>
                    <p className="mt-0.5 text-xs leading-relaxed text-text-2">
                        {active.detail}
                    </p>
                </div>
            )}
        </Card>
    );
}
