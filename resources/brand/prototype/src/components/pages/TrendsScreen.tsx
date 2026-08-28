import {
    CategoryScale,
    Chart as ChartJS,
    LinearScale,
    LineElement,
    PointElement,
    Legend,
    LineController,
    Tooltip,
    type ChartOptions,
} from 'chart.js';
import { Clock, Medal, RefreshCw, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState, type CSSProperties } from 'react';
import { Line } from 'react-chartjs-2';

import { CHART_PALETTE } from '@/lib/palette';
import { cn } from '@/lib/utils';

ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    LineController,
    Tooltip,
    Legend,
);

type Rarity = 'common' | 'uncommon' | 'rare' | 'epic' | 'legendary';

const rarityVar = (r: Rarity) => `var(--rarity-${r})`;

const RANGES = ['30 days', '90 days', '12 months'] as const;

const STAT_ROW = [
    { value: '62', label: 'fitness' },
    { value: '58', label: 'fatigue' },
    { value: '+4', label: 'form' },
] as const;

const BADGES = [
    {
        key: 'first-10k',
        label: 'first 10k',
        rarity: 'rare' as Rarity,
        detail: 'your first double-digit run — the week fitness started climbing in earnest.',
    },
    {
        key: 'hm-pr',
        label: 'half marathon pr',
        rarity: 'epic' as Rarity,
        detail: '2 minutes faster than your last attempt, off the back of a heavy training week.',
    },
    {
        key: 'streak-6',
        label: '6-week streak',
        rarity: 'uncommon' as Rarity,
        detail: 'six consecutive weeks with at least one run logged — your longest active streak yet.',
    },
] as const;

/** Deterministic 90-day CTL/ATL series — steady buildup, weekly ATL spikes. */
function genFitnessSeries(days: number) {
    const labels: number[] = [];
    const ctl: number[] = [];
    const atl: number[] = [];
    let c = 38;
    for (let i = 0; i < days; i++) {
        c += (62 - 38) / days + Math.sin(i / 9) * 0.15;
        const a = c - 4 + Math.sin(i / 5) * 9 + (i % 7 === 0 ? 8 : 0);
        labels.push(i);
        ctl.push(Math.round(c * 10) / 10);
        atl.push(Math.round(a * 10) / 10);
    }
    return { labels, ctl, atl };
}

function useIsChartDark(theme: 'light' | 'dark' | 'system') {
    const [systemDark, setSystemDark] = useState(
        () => window.matchMedia('(prefers-color-scheme: dark)').matches,
    );
    useEffect(() => {
        const mql = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => setSystemDark(mql.matches);
        mql.addEventListener('change', onChange);
        return () => mql.removeEventListener('change', onChange);
    }, []);
    if (theme === 'dark') return true;
    if (theme === 'light') return false;
    return systemDark;
}

function FitnessChart({ isDark }: Readonly<{ isDark: boolean }>) {
    const series = useMemo(() => genFitnessSeries(90), []);
    const palette = isDark ? CHART_PALETTE.dark : CHART_PALETTE.light;

    const data = {
        labels: series.labels,
        datasets: [
            {
                data: series.ctl,
                borderColor: palette.line,
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.35,
            },
            {
                data: series.atl,
                borderColor: palette.tick,
                backgroundColor: 'transparent',
                borderWidth: 1.5,
                borderDash: [3, 3],
                pointRadius: 0,
                tension: 0.35,
            },
        ],
    };

    const options: ChartOptions<'line'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: {
            x: { display: false },
            y: {
                grid: { color: palette.grid },
                ticks: {
                    color: palette.tick,
                    font: { family: 'JetBrains Mono', size: 9 },
                    maxTicksLimit: 4,
                },
                border: { display: false },
            },
        },
        elements: { point: { hoverRadius: 0 } },
    };

    return (
        <div className="mb-2.5 h-[150px]">
            <Line data={data} options={options} />
        </div>
    );
}

function NarrationCard({
    regenState,
}: Readonly<{ regenState: 'ready' | 'cooldown' }>) {
    return (
        <div className="mb-4.5 rounded-[14px] border-[1.5px] border-[color-mix(in_oklab,var(--horizon-ink)_45%,var(--border-strong-fg))] bg-card p-4 shadow-[0_1px_2px_rgba(58,45,20,.06),0_1px_1px_rgba(58,45,20,.04),0_0_0_3px_color-mix(in_oklab,var(--horizon)_14%,transparent)]">
            <div className="mb-1.5 flex items-center gap-1.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-icon-accent uppercase">
                <Sparkles className="size-3" aria-hidden />
                temari&apos;s read
            </div>
            <p className="m-0 mb-1.25 font-serif text-[15px] leading-[1.2] font-bold text-foreground italic">
                building at a steady clip.
            </p>
            <p className="m-0 font-serif text-[12.5px] leading-[1.55] text-foreground italic">
                fitness has climbed for six straight weeks without a real dip in
                form — the half marathon pr three weeks back barely dented
                recovery. this is the kind of buildup that holds.
            </p>
            <div className="mt-2.5 flex justify-end">
                {regenState === 'cooldown' ? (
                    <span className="inline-flex cursor-not-allowed items-center gap-1.25 rounded-full bg-muted px-2.75 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                        <Clock className="size-3" aria-hidden />
                        next read in 4h 12m
                    </span>
                ) : (
                    <button
                        type="button"
                        className="inline-flex items-center gap-1.25 rounded-full border-none bg-muted px-2.75 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase"
                    >
                        <RefreshCw className="size-3" aria-hidden />
                        regenerate
                    </button>
                )}
            </div>
        </div>
    );
}

function FitnessPanel({ isDark }: Readonly<{ isDark: boolean }>) {
    const [openBadge, setOpenBadge] = useState<string | null>(null);
    const active = BADGES.find((b) => b.key === openBadge);

    return (
        <div className="rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-1 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                fitness
            </div>
            <p className="m-0 mb-1.5 font-serif text-base leading-[1.2] font-bold text-foreground">
                climbing, not spiking.
            </p>
            <p className="m-0 mb-3.5 text-[11.5px] leading-[1.5] text-foreground">
                fitness (ctl) tracks your rolling training load; fatigue (atl)
                reacts faster. form is the gap between them — positive means
                you&apos;re absorbing the work.
            </p>

            <div className="mb-3.5 grid grid-cols-3 gap-2">
                {STAT_ROW.map((s) => (
                    <div
                        key={s.label}
                        className="rounded-[10px] bg-muted p-2.25 text-center"
                    >
                        <b className="block font-mono text-base leading-[1.2] font-extrabold text-foreground">
                            {s.value}
                        </b>
                        <span className="font-mono text-[8px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                            {s.label}
                        </span>
                    </div>
                ))}
            </div>

            <FitnessChart isDark={isDark} />

            <div className="mb-3.5 flex gap-3.5 px-0.5">
                <div className="flex items-center gap-1.25 font-mono text-[9px] leading-[1.2] font-bold tracking-[.03em] text-foreground uppercase">
                    <span className="h-0.5 w-3 flex-none rounded-[2px] bg-icon-accent" />
                    fitness
                </div>
                <div className="flex items-center gap-1.25 font-mono text-[9px] leading-[1.2] font-bold tracking-[.03em] text-foreground uppercase">
                    <span className="h-0.5 w-3 flex-none rounded-[2px] bg-[repeating-linear-gradient(90deg,var(--text-3-fg)_0_4px,transparent_4px_7px)]" />
                    fatigue
                </div>
            </div>

            <div className="flex flex-wrap gap-1.5">
                {BADGES.map((b) => {
                    const isOpen = openBadge === b.key;
                    return (
                        <button
                            key={b.key}
                            type="button"
                            onClick={() =>
                                setOpenBadge((prev) =>
                                    prev === b.key ? null : b.key,
                                )
                            }
                            className="inline-flex items-center gap-1.25 rounded-full border-none px-2.5 py-1.5 font-sans text-[11px] leading-[1.2] font-bold text-foreground"
                            style={
                                isOpen
                                    ? {
                                          background: `color-mix(in oklab, ${rarityVar(b.rarity)} 18%, var(--muted))`,
                                          color: 'var(--foreground)',
                                      }
                                    : { background: 'var(--muted)' }
                            }
                        >
                            <Medal
                                className="size-3.25"
                                style={
                                    {
                                        color: rarityVar(b.rarity),
                                    } as CSSProperties
                                }
                                aria-hidden
                            />
                            {b.label}
                        </button>
                    );
                })}
            </div>

            {active && (
                <div className="mt-2.5 rounded-[10px] bg-muted px-3 py-2.5">
                    <div className="mb-0.75 flex items-center gap-1.5 text-[12.5px] leading-[1.2] font-bold text-foreground">
                        <Medal
                            className="size-3.5"
                            style={
                                {
                                    color: rarityVar(active.rarity),
                                } as CSSProperties
                            }
                            aria-hidden
                        />
                        {active.label}
                    </div>
                    <p className="m-0 text-[11.5px] leading-[1.5] text-foreground">
                        {active.detail}
                    </p>
                </div>
            )}
        </div>
    );
}

export function TrendsScreen({
    theme,
    regenState,
}: Readonly<{
    theme: 'light' | 'dark' | 'system';
    regenState: 'ready' | 'cooldown';
}>) {
    const [range, setRange] = useState<(typeof RANGES)[number]>('90 days');
    const isDark = useIsChartDark(theme);

    return (
        <div className="px-4 pt-16 pb-22 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-24">
            <div className="mt-3 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.12em] text-foreground uppercase">
                trends
            </div>
            <h1 className="m-0 mt-2 mb-2 font-serif text-[24px] leading-[1.15] font-semibold text-foreground italic">
                how things
                <br />
                <em className="text-icon-accent">are going.</em>
            </h1>
            <p className="m-0 mb-4 text-xs leading-[1.55] text-foreground">
                a year of running, read as lines rather than a list.
            </p>

            <nav className="mb-4 flex gap-1 rounded-full bg-muted p-1">
                {RANGES.map((r) => (
                    <button
                        key={r}
                        type="button"
                        onClick={() => setRange(r)}
                        className={cn(
                            'flex-1 rounded-full py-2 text-center font-sans text-[11px] leading-[1.2] font-bold',
                            r === range
                                ? 'bg-card text-foreground shadow-e1'
                                : 'text-foreground',
                        )}
                    >
                        {r}
                    </button>
                ))}
            </nav>

            <NarrationCard regenState={regenState} />

            <FitnessPanel isDark={isDark} />
        </div>
    );
}
