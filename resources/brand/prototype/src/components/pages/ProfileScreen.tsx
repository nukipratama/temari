import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import {
    ArrowRight,
    Flag,
    Footprints,
    Gauge,
    Route,
    Timer,
    Trophy,
} from 'lucide-react';

import { FaceIcon } from '@/components/FaceIcon';
import { cn } from '@/lib/utils';

const ZONES = [
    { key: 'z1', label: 'Z1 recovery', pct: 15, color: 'var(--leaf)' },
    { key: 'z2', label: 'Z2 aerobic', pct: 42, color: 'var(--horizon)' },
    { key: 'z3', label: 'Z3 tempo', pct: 25, color: 'var(--citrus)' },
    { key: 'z4', label: 'Z4 threshold', pct: 13, color: 'var(--ember)' },
    { key: 'z5', label: 'Z5 max', pct: 5, color: 'var(--zone-max)' },
] as const;

const STATS = [
    { icon: Route, value: '284.6', label: 'total km' },
    { icon: Footprints, value: '42', label: 'total runs' },
    { icon: Trophy, value: '21.20', label: 'longest run' },
    { icon: Gauge, value: '48.2', label: 'vdot' },
    { icon: Timer, value: '4:52/km', label: 'threshold' },
] as const;

// Mirrors the Plan page's season timeline: base is the current phase this
// season, the rest haven't started yet.
const PHASES: {
    key: string;
    label: string;
    state: 'done' | 'current' | 'upcoming';
}[] = [
    { key: 'base', label: 'base', state: 'current' },
    { key: 'build', label: 'build', state: 'upcoming' },
    { key: 'peak', label: 'peak', state: 'upcoming' },
    { key: 'taper', label: 'taper', state: 'upcoming' },
];

const PACE_MARKERS = [
    { label: 'easy', pace: '6:10', left: 0, below: false },
    { label: 'marathon', pace: '5:20', left: 49, below: true },
    { label: 'tempo', pace: '4:52', left: 76, below: false },
    { label: 'interval', pace: '4:28', left: 100, below: true },
] as const;

const DIST_TABS = ['5K', '10K', 'HM', 'FM'] as const;

const JOURNEY_POINTS = [
    { x: 0, y: 15, label: '25 may 2026', time: '52:40', pr: false },
    { x: 45, y: 22, label: '8 jun 2026', time: '51:57', pr: false },
    { x: 90, y: 20, label: '22 jun 2026', time: '52:09', pr: false },
    { x: 135, y: 34, label: '6 jul 2026', time: '50:43', pr: false },
    { x: 180, y: 30, label: '20 jul 2026', time: '51:08', pr: false },
    { x: 225, y: 48, label: '3 aug 2026', time: '49:17', pr: false },
    { x: 270, y: 44, label: '17 aug 2026', time: '49:41', pr: false },
    { x: 300, y: 58, label: '31 aug 2026', time: '48:15', pr: true },
] as const;

function HeroPanel() {
    return (
        <div className="relative mb-4 overflow-hidden rounded-[26px] bg-card p-5 text-foreground shadow-[0_4px_10px_rgba(58,45,20,.08),0_2px_4px_rgba(58,45,20,.05)] after:pointer-events-none after:absolute after:inset-0 after:rounded-[26px] after:border-2 after:border-border-strong after:shadow-[0_0_0_1.5px_color-mix(in_oklab,var(--horizon)_45%,transparent)] after:content-['']">
            <div
                className="pointer-events-none absolute -top-15 -right-15 size-[220px] rounded-full"
                style={{
                    background:
                        'radial-gradient(circle, color-mix(in oklab, var(--horizon) 22%, transparent) 0%, transparent 70%)',
                }}
            />

            <div className="relative mb-3.5 flex items-center gap-3.5">
                <div
                    className="flex-none"
                    style={{
                        filter: 'drop-shadow(0 0 10px color-mix(in oklab, var(--horizon) 45%, transparent))',
                    }}
                >
                    <FaceIcon
                        size={64}
                        ring="var(--leaf)"
                        fill="var(--card)"
                        feature="var(--foreground)"
                    />
                </div>
                <div>
                    <div className="mb-1.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                        ★ what temari says about you
                    </div>
                    <div className="inline-flex items-center gap-1 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground">
                        est.{' '}
                        <b className="font-extrabold text-foreground">
                            12 jun 2026
                        </b>
                    </div>
                </div>
                <div className="ml-auto hidden flex-none text-right @min-[900px]:block">
                    <div className="mb-1 font-mono text-[10px] leading-[1.2] font-bold tracking-[.12em] text-foreground uppercase">
                        with temari since
                    </div>
                    <p className="m-0 font-serif text-base leading-[1.2] text-foreground">
                        12 jun 2026
                    </p>
                </div>
            </div>

            <p className="relative mb-4.5 font-serif text-sm leading-[1.55] text-foreground italic">
                you&apos;ve turned tempo tuesdays into a habit — three weeks
                running now, and it shows in the last km of every long run.
            </p>

            <div className="relative mb-1.5 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.07em] text-foreground uppercase">
                time in zone · last 12 weeks
            </div>
            <div className="relative mb-2 flex h-[9px] gap-[3px]">
                {ZONES.map((z) => (
                    <i
                        key={z.key}
                        className="block h-full rounded-full"
                        style={{ width: `${z.pct}%`, background: z.color }}
                    />
                ))}
            </div>
            <div className="relative mb-5 flex flex-wrap gap-x-2.5 gap-y-[3px]">
                {ZONES.map((z) => (
                    <span
                        key={z.key}
                        className="inline-flex items-center gap-1 font-mono text-[8px] leading-[1.2] tracking-[.03em] text-foreground uppercase"
                    >
                        <i
                            className="inline-block size-1.5 rounded-full"
                            style={{ background: z.color }}
                        />
                        {z.label} {z.pct}%
                    </span>
                ))}
            </div>

            <div className="relative -mx-5 mb-0.5 border-t border-border-strong" />
            <div className="relative -mx-5 flex gap-2 overflow-x-auto px-5 pt-3.5 pb-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {STATS.map((s) => (
                    <div
                        key={s.label}
                        className="w-[92px] flex-none rounded-[10px] bg-muted px-2.5 py-3 text-center shadow-[0_0_0_1px_color-mix(in_oklab,var(--horizon)_28%,transparent)]"
                    >
                        <s.icon
                            className="mx-auto mb-1.75 size-[17px] text-icon-accent"
                            aria-hidden
                        />
                        <b className="block font-mono text-base leading-[1.2] font-extrabold text-foreground">
                            {s.value}
                        </b>
                        <span className="mt-0.5 block font-mono text-[7.5px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                            {s.label}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function NoRaceCard({
    onNavigateRace,
}: Readonly<{ onNavigateRace: () => void }>) {
    return (
        <a
            href="#"
            onClick={(e) => {
                e.preventDefault();
                onNavigateRace();
            }}
            className="mb-4 flex items-center justify-between gap-2.5 rounded-[14px] border border-border-strong bg-card px-4 py-4 text-foreground no-underline shadow-e1"
        >
            <span className="flex items-center gap-2 text-[12.5px] leading-[1.2] font-bold">
                <Flag className="size-[15px]" aria-hidden />
                got a race coming up?
            </span>
            <span className="font-mono text-[10px] leading-[1.2] text-foreground">
                set your race →
            </span>
        </a>
    );
}

function HasRaceCard({
    onNavigateRace,
}: Readonly<{ onNavigateRace: () => void }>) {
    return (
        <a
            href="#"
            onClick={(e) => {
                e.preventDefault();
                onNavigateRace();
            }}
            className="mb-4 flex items-center gap-3 rounded-[14px] border border-border-strong bg-card px-4 py-4 text-foreground no-underline shadow-e1"
        >
            <Flag className="size-5 flex-none text-icon-accent" aria-hidden />
            <div className="min-w-0 flex-1">
                <b className="block text-[12.5px] leading-[1.2] font-bold">
                    jakarta half marathon
                </b>
                <span className="mt-0.5 block font-mono text-[10px] leading-[1.2] text-foreground">
                    21.1 km · 12 oct 2026
                </span>
            </div>
            <div className="flex-none text-center">
                <b className="block font-mono text-lg leading-none font-extrabold text-icon-accent">
                    42
                </b>
                <span className="block font-mono text-[7.5px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                    days
                </span>
            </div>
        </a>
    );
}

function SeasonCard({ planState }: Readonly<{ planState: 'has' | 'empty' }>) {
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                season
            </div>
            {planState === 'empty' ? (
                <p className="m-0 mt-2 text-[11px] leading-[1.5] text-foreground">
                    no season yet.{' '}
                    <a
                        href="#"
                        className="inline-flex items-center gap-0.5 font-bold text-icon-accent no-underline"
                    >
                        start one on plan
                        <ArrowRight className="size-3" aria-hidden />
                    </a>
                </p>
            ) : (
                <>
                    <div className="my-2 text-[11px] leading-[1.2] text-foreground">
                        base · 12 jun – 4 sep
                    </div>
                    <div className="mb-2 flex gap-1">
                        {PHASES.map((p) => (
                            <div
                                key={p.key}
                                className={cn(
                                    'flex h-[15px] flex-1 items-center justify-center overflow-hidden rounded',
                                    p.state === 'current' &&
                                        'bg-[repeating-linear-gradient(115deg,var(--horizon),var(--horizon)_3px,var(--horizon-deep)_3px,var(--horizon-deep)_6px)]',
                                    p.state === 'done' && 'bg-icon-accent',
                                    p.state === 'upcoming' &&
                                        'bg-border-strong',
                                )}
                            >
                                <span
                                    className={cn(
                                        'font-mono text-[6.5px] leading-[1.2] font-extrabold tracking-[.03em] uppercase',
                                        p.state === 'upcoming'
                                            ? 'text-foreground'
                                            : 'text-sky',
                                    )}
                                >
                                    {p.label}
                                </span>
                            </div>
                        ))}
                    </div>
                    <div className="mb-1.5 h-[5px] overflow-hidden rounded-full bg-border-strong">
                        <div
                            className="h-full bg-horizon"
                            style={{ width: '62%' }}
                        />
                    </div>
                    <div className="text-[9.5px] leading-[1.2] text-foreground">
                        62% to sub-50 10K
                    </div>
                </>
            )}
        </div>
    );
}

function PaceCard() {
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card px-5 py-4 shadow-e1">
            <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                training · pace targets · per km
            </div>
            <div className="relative mx-2 h-[78px]">
                <div className="absolute inset-x-0 top-[39px] h-1 rounded-full bg-gradient-to-r from-leaf to-horizon" />
                {PACE_MARKERS.map((m) => (
                    <div
                        key={m.label}
                        className={cn(
                            'absolute flex -translate-x-1/2 flex-col items-center gap-1.5',
                            m.below ? 'top-auto bottom-0' : 'top-0',
                        )}
                        style={{ left: `${m.left}%` }}
                    >
                        {!m.below && (
                            <span className="text-center leading-[1.2] whitespace-nowrap">
                                <b className="block font-mono text-xs leading-[1.2] font-extrabold text-foreground">
                                    {m.pace}
                                </b>
                                <span className="block font-mono text-[7px] leading-[1.2] tracking-[.03em] text-foreground uppercase">
                                    {m.label}
                                </span>
                            </span>
                        )}
                        <i className="size-[9px] flex-none rounded-full bg-foreground shadow-[0_0_0_3px_var(--card)]" />
                        {m.below && (
                            <span className="text-center leading-[1.2] whitespace-nowrap">
                                <b className="block font-mono text-xs leading-[1.2] font-extrabold text-foreground">
                                    {m.pace}
                                </b>
                                <span className="block font-mono text-[7px] leading-[1.2] tracking-[.03em] text-foreground uppercase">
                                    {m.label}
                                </span>
                            </span>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

function JourneyChart() {
    const chartRef = useRef<HTMLDivElement>(null);
    const tipRef = useRef<HTMLDivElement>(null);
    const [tip, setTip] = useState<{
        x: number;
        y: number;
        label: string;
        time: string;
        pr: boolean;
    } | null>(null);

    useEffect(() => {
        function handleOutsideClick(e: MouseEvent) {
            if (
                chartRef.current &&
                !chartRef.current.contains(e.target as Node)
            )
                setTip(null);
        }
        document.addEventListener('click', handleOutsideClick);
        return () => document.removeEventListener('click', handleOutsideClick);
    }, []);

    useLayoutEffect(() => {
        if (!tip || !tipRef.current || !chartRef.current) return;
        const chartWidth = chartRef.current.clientWidth;
        const halfTipWidth = tipRef.current.offsetWidth / 2;
        const clampedX = Math.min(
            Math.max(tip.x, halfTipWidth + 4),
            chartWidth - halfTipWidth - 4,
        );
        tipRef.current.style.left = `${clampedX}px`;
    }, [tip]);

    function handlePointClick(
        e: React.MouseEvent<SVGAElement>,
        label: string,
        time: string,
        pr: boolean,
    ) {
        e.preventDefault();
        const dot = e.currentTarget.querySelector('[data-jc-dot]');
        if (!chartRef.current || !dot) return;
        const chartRect = chartRef.current.getBoundingClientRect();
        const dotRect = dot.getBoundingClientRect();
        setTip((prev) =>
            prev?.label === label
                ? null
                : {
                      x: dotRect.left + dotRect.width / 2 - chartRect.left,
                      y: dotRect.top - chartRect.top,
                      label,
                      time,
                      pr,
                  },
        );
    }

    const points = JOURNEY_POINTS.map((p) => `${p.x},${p.y}`).join(' ');

    return (
        <div ref={chartRef} className="relative mt-3.5">
            <svg
                viewBox="-8 0 316 78"
                width="100%"
                height="78"
                preserveAspectRatio="none"
            >
                <defs>
                    <linearGradient
                        id="journeyFade"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stopColor="var(--horizon-ink)"
                            stopOpacity="0.28"
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--horizon-ink)"
                            stopOpacity="0"
                        />
                    </linearGradient>
                </defs>
                <polygon
                    points={`${points} 300,78 0,78`}
                    fill="url(#journeyFade)"
                />
                <polyline
                    points={points}
                    fill="none"
                    stroke="var(--horizon-ink)"
                    strokeWidth="2.5"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
                {JOURNEY_POINTS.map((p) => (
                    <a
                        key={p.label}
                        href="#"
                        aria-label={
                            p.pr
                                ? 'View your current PR week'
                                : "View that week's run"
                        }
                        onClick={(e) =>
                            handlePointClick(e, p.label, p.time, p.pr)
                        }
                        className="group cursor-pointer"
                    >
                        <circle cx={p.x} cy={p.y} r="10" fill="transparent" />
                        <circle
                            data-jc-dot
                            cx={p.x}
                            cy={p.y}
                            r={p.pr ? 5 : 2.5}
                            fill={
                                p.pr ? 'var(--horizon)' : 'var(--horizon-ink)'
                            }
                            stroke={p.pr ? 'var(--card)' : undefined}
                            strokeWidth={p.pr ? 2 : undefined}
                            className="origin-center transition-transform [transform-box:fill-box] group-hover:scale-170 group-focus:scale-170"
                        />
                    </a>
                ))}
                <text
                    x="300"
                    y="45"
                    textAnchor="end"
                    fontFamily="var(--font-mono)"
                    fontSize="8"
                    fontWeight="800"
                    fill="var(--horizon-ink)"
                >
                    PR
                </text>
            </svg>
            {tip && (
                <div
                    ref={tipRef}
                    className="pointer-events-none absolute -translate-x-1/2 -translate-y-[130%] rounded-md bg-ink px-2.25 py-1.25 font-mono text-[10px] leading-[1.2] font-bold whitespace-nowrap text-cream shadow-[0_4px_10px_rgba(58,45,20,.08),0_2px_4px_rgba(58,45,20,.05)]"
                    style={{ left: tip.x, top: tip.y }}
                >
                    {tip.label}
                    {tip.pr ? ' · PR' : ''} · {tip.time}
                    <span className="absolute top-full left-1/2 -translate-x-1/2 border-[5px] border-transparent border-t-ink" />
                </div>
            )}
        </div>
    );
}

function ProgressionCard() {
    return (
        <div className="rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-3.5 flex flex-wrap gap-1.5">
                {DIST_TABS.map((t) => (
                    <span
                        key={t}
                        className={cn(
                            'rounded-full border px-2.75 py-1.25 font-mono text-[10.5px] leading-[1.2] font-extrabold',
                            t === '10K'
                                ? 'border-foreground bg-foreground text-background'
                                : 'border-border-strong text-foreground',
                        )}
                    >
                        {t}
                    </span>
                ))}
            </div>
            <div className="mb-0.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                journey · 10K
            </div>
            <p className="m-0 mt-1 font-serif text-lg leading-[1.2] font-semibold text-foreground">
                then 52:40, now{' '}
                <em className="text-icon-accent italic">48:15</em>
            </p>
            <p className="m-0 mt-2 font-serif text-[12.5px] leading-[1.3] text-foreground italic">
                &quot;4:25 faster over 14 weeks.&quot;
            </p>
            <div className="mt-2.5 flex flex-wrap gap-1.5">
                <span className="rounded-full bg-muted px-2.25 py-1 text-[10px] leading-[1.2] font-bold text-foreground">
                    −4:25 total
                </span>
                <span className="rounded-full bg-horizon/20 px-2.25 py-1 text-[10px] leading-[1.2] font-bold text-icon-accent">
                    goal: sub-50:00
                </span>
            </div>
            <JourneyChart />
        </div>
    );
}

export function ProfileScreen({
    raceState,
    planState,
    onNavigateRace,
}: Readonly<{
    raceState: 'unset' | 'set';
    planState: 'has' | 'empty';
    onNavigateRace: () => void;
}>) {
    return (
        <div className="px-4 pt-16 pb-7 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-22">
            <div className="mt-3 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.12em] text-foreground uppercase">
                profile
            </div>
            <div className="mt-2 mb-5 flex items-start justify-between gap-3">
                <h1 className="m-0 font-serif text-[26px] leading-[1.12] font-semibold text-foreground italic">
                    nuki,
                    <br />
                    <em className="text-icon-accent">your story.</em>
                </h1>
                <div
                    className="mt-[7px] flex size-11 flex-none items-center justify-center rounded-full bg-muted font-mono text-base font-bold text-foreground"
                    style={{ boxShadow: '0 0 0 2px var(--icon-accent-fg)' }}
                >
                    N
                </div>
            </div>

            <HeroPanel />

            {raceState === 'set' ? (
                <HasRaceCard onNavigateRace={onNavigateRace} />
            ) : (
                <NoRaceCard onNavigateRace={onNavigateRace} />
            )}

            <SeasonCard planState={planState} />
            <PaceCard />
            <ProgressionCard />
        </div>
    );
}
