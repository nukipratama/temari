import {
    ArrowRight,
    Bed,
    ChevronDown,
    ChevronRight,
    Feather,
    Flame,
} from 'lucide-react';

import { FaceIcon } from '@/components/FaceIcon';
import { ProgressRing } from '@/components/ProgressRing';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

type DayType = 'easy' | 'tempo' | 'long run' | 'rest';
type DayStatus =
    'done' | 'partial' | 'missed' | 'overreached' | 'skip' | 'upcoming';

// Same shape-per-intensity convention as the Plan page: quality/hard days
// read as a flame, easy/long days as a feather, rest as a bed — not just a
// generic done/not-done dot.
const TYPE_ICON: Record<DayType, typeof Flame> = {
    tempo: Flame,
    easy: Feather,
    'long run': Feather,
    rest: Bed,
};

// Same compliance palette as the Plan page — reused here so a session's
// state reads the same color on both pages.
const STATUS_ICON_STYLE: Record<DayStatus, string> = {
    done: 'text-icon-accent',
    partial: 'text-citrus',
    missed: 'text-destructive',
    overreached: 'text-[#d97706]',
    skip: 'text-foreground',
    upcoming: 'text-foreground',
};

type WeekPhase = 'base' | 'build' | 'peak' | 'taper';

// Mirrors the Plan page's SEASON_WEEKS average weekly km per phase — traced
// here as a compact sparkline instead of the full-width bars.
const PHASE_AVG_KM: Record<WeekPhase, number> = {
    base: 31.7,
    build: 39,
    peak: 43,
    taper: 24,
};
const PHASE_ORDER: WeekPhase[] = ['base', 'build', 'peak', 'taper'];
const CURRENT_PHASE: WeekPhase = 'base';

// Same per-phase identity colors as the Plan page — validated distinct via
// the dataviz skill's contrast script.
const PHASE_COLOR: Record<WeekPhase, string> = {
    base: '#0d9488',
    build: '#1e6fe0',
    peak: '#a21caf',
    taper: '#db2777',
};

function phaseBarHeightPx(phase: WeekPhase): number {
    const values = Object.values(PHASE_AVG_KM);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const pct = (PHASE_AVG_KM[phase] - min) / (max - min);
    return 4 + pct * 8;
}

function PhaseSparkline() {
    return (
        <span className="inline-flex items-end gap-[3px]">
            {PHASE_ORDER.map((p) => (
                <span
                    key={p}
                    className="w-[3px] rounded-full"
                    style={{
                        height: `${phaseBarHeightPx(p)}px`,
                        backgroundColor:
                            p === CURRENT_PHASE
                                ? PHASE_COLOR[p]
                                : `color-mix(in oklab, ${PHASE_COLOR[p]} 30%, transparent)`,
                    }}
                    aria-hidden
                />
            ))}
        </span>
    );
}

// Mirrors the Plan page's current-week data: mon overreached, tue partial,
// wed a rest day someone ran anyway, thu is today, fri/sat/sun upcoming.
const DAYS = [
    {
        wd: 'mon',
        type: 'easy' as DayType,
        status: 'overreached' as DayStatus,
        today: false,
        ranAnyway: false,
        dist: '8.1k',
    },
    {
        wd: 'tue',
        type: 'tempo' as DayType,
        status: 'partial' as DayStatus,
        today: false,
        ranAnyway: false,
        dist: '6.5k',
    },
    {
        wd: 'wed',
        type: 'rest' as DayType,
        status: 'upcoming' as DayStatus,
        today: false,
        ranAnyway: true,
        dist: '4.2k',
    },
    {
        wd: 'thu',
        type: 'easy' as DayType,
        status: 'upcoming' as DayStatus,
        today: true,
        ranAnyway: false,
        dist: '6k',
    },
    {
        wd: 'fri',
        type: 'rest' as DayType,
        status: 'upcoming' as DayStatus,
        today: false,
        ranAnyway: false,
        dist: 'rest',
    },
    {
        wd: 'sat',
        type: 'long run' as DayType,
        status: 'upcoming' as DayStatus,
        today: false,
        ranAnyway: false,
        dist: '14k',
    },
    {
        wd: 'sun',
        type: 'rest' as DayType,
        status: 'upcoming' as DayStatus,
        today: false,
        ranAnyway: false,
        dist: 'rest',
    },
] as const;

const EVIDENCE = [
    {
        label: '6.2 km · pace vs jun 3',
        before: '5:43',
        now: '5:32',
        delta: '-11 s/km',
    },
    {
        label: '10 km · pace vs may 28',
        before: '5:38',
        now: '5:29',
        delta: '-9 s/km',
    },
] as const;

function DayCell({
    wd,
    type,
    status,
    today,
    ranAnyway,
    dist,
    index,
}: Readonly<{
    wd: string;
    type: DayType;
    status: DayStatus;
    today: boolean;
    ranAnyway: boolean;
    dist: string;
    index: number;
}>) {
    const Icon = TYPE_ICON[type];
    const isRest = type === 'rest';
    const happened = isRest
        ? ranAnyway
        : status !== 'upcoming' && status !== 'skip';
    let colorClass = STATUS_ICON_STYLE[status];
    if (isRest) {
        colorClass = ranAnyway ? 'text-leaf' : 'text-foreground';
    }
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-0.75 rounded-lg py-1.25',
                today && 'shadow-[inset_0_0_0_1.5px_var(--icon-accent-fg)]',
            )}
        >
            <span className="font-mono text-[8.5px] leading-[1.2] text-foreground uppercase">
                {wd}
            </span>
            {happened ? (
                <Icon
                    className={cn('size-[13px] animate-flame-pop', colorClass)}
                    strokeWidth={2.5}
                    style={{ animationDelay: `${index * 70}ms` }}
                    aria-hidden
                />
            ) : (
                <Icon
                    className="size-[13px] text-foreground"
                    strokeWidth={2}
                    aria-hidden
                />
            )}
            <span className="font-mono text-[7.5px] leading-[1.2] text-foreground">
                {dist}
            </span>
        </div>
    );
}

function PlanCard() {
    return (
        <div className="mt-3 mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2">
                <span className="font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                    this week&apos;s plan
                </span>
                <Badge className="h-auto gap-1.5 rounded-full bg-muted px-2.25 py-1 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase">
                    <PhaseSparkline />
                    base
                </Badge>
            </div>

            <div className="mb-3.5 flex items-center gap-4 @min-[900px]:gap-6">
                <ProgressRing credited={3} total={5} />
                <div className="grid flex-1 grid-cols-2 gap-2">
                    <div>
                        <b className="block font-mono text-[15px] leading-[1.2] font-extrabold text-foreground">
                            3/5
                        </b>
                        <span className="text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                            sessions
                        </span>
                    </div>
                    <div>
                        <b className="block font-mono text-[15px] leading-[1.2] font-extrabold text-foreground">
                            18.2
                        </b>
                        <span className="text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                            km this week
                        </span>
                    </div>
                </div>
            </div>

            <div className="mb-3.5 grid grid-cols-7 gap-1">
                {DAYS.map((d, i) => (
                    <DayCell key={d.wd} {...d} index={i} />
                ))}
            </div>

            <a
                href="#"
                className="flex items-center justify-between gap-2 rounded-[10px] bg-muted px-3 py-2.5 text-[11.5px] leading-[1.2] text-foreground no-underline"
            >
                <span>
                    <b className="text-foreground">today</b> · easy · 6 km ·
                    5:38–5:55/km
                </span>
                <ChevronRight
                    className="size-4 flex-none text-foreground"
                    aria-hidden
                />
            </a>
        </div>
    );
}

function NoPlanCard() {
    return (
        <div className="mt-3 mb-4 flex items-center gap-3.5 rounded-[14px] border border-border-strong bg-card p-4.5 shadow-e1">
            <FaceIcon
                size={40}
                ring="var(--horizon)"
                fill="var(--card)"
                feature="var(--foreground)"
            />
            <div>
                <p className="m-0 font-serif text-base leading-[1.2] font-semibold text-foreground italic">
                    no plan yet.
                </p>
                <p className="mt-1 mb-2.5 text-xs leading-[1.5] text-foreground">
                    set one up and temari will lay out the weeks ahead.
                </p>
                <a
                    href="#"
                    className="inline-flex items-center gap-1 text-[11.5px] leading-[1.2] font-bold text-icon-accent no-underline"
                >
                    set up a plan
                    <ArrowRight className="size-3" aria-hidden />
                </a>
            </div>
        </div>
    );
}

function EvidenceRow({
    label,
    before,
    now,
    delta,
}: Readonly<{ label: string; before: string; now: string; delta: string }>) {
    return (
        <div className="flex flex-col gap-1 bg-card px-3.5 py-2.75">
            <span className="text-[10.5px] leading-[1.2] text-foreground">
                {label}
            </span>
            <div className="flex items-baseline gap-2 font-mono">
                <span className="text-[12.5px] leading-[1.2] text-foreground">
                    {before}
                </span>
                <span className="text-xs leading-[1.2] text-foreground">→</span>
                <span className="text-[14.5px] leading-[1.2] font-extrabold text-foreground">
                    {now}
                </span>
                <span className="ml-auto rounded-full bg-horizon/20 px-2 py-0.75 text-[10px] leading-[1.2] font-extrabold text-icon-accent">
                    {delta}
                </span>
            </div>
        </div>
    );
}

function StatFigure({
    value,
    label,
}: Readonly<{ value: string; label: string }>) {
    return (
        <span className="inline-flex items-baseline gap-1">
            <b className="font-mono text-[15px] leading-[1.2] font-extrabold text-foreground">
                {value}
            </b>
            <span className="font-mono text-[10px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                {label}
            </span>
        </span>
    );
}

function VitalRow({
    label,
    value,
    pct,
    sub,
    tone,
}: Readonly<{
    label: string;
    value: string;
    pct: number;
    sub: string;
    tone: 'good' | 'watch';
}>) {
    return (
        <div className="flex items-center gap-3">
            <div className="w-19 flex-none">
                <span className="block font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    {label}
                </span>
                <b
                    className={cn(
                        'block text-[13px] leading-[1.2] font-extrabold',
                        tone === 'watch'
                            ? 'text-citrus-ink'
                            : 'text-foreground',
                    )}
                >
                    {value}
                </b>
            </div>
            <div className="flex-1">
                <div className="h-0.75 overflow-hidden rounded-full bg-border-strong">
                    <div
                        className={cn(
                            'h-full',
                            tone === 'watch' ? 'bg-citrus' : 'bg-horizon',
                        )}
                        style={{ width: `${pct}%` }}
                    />
                </div>
                <span className="mt-1 block text-[9.5px] leading-[1.2] text-foreground italic">
                    {sub}
                </span>
            </div>
        </div>
    );
}

function MiniRow({ label, value }: Readonly<{ label: string; value: string }>) {
    return (
        <div className="flex justify-between border-b border-border-strong py-1 text-[11px] leading-[1.2] last:border-b-0">
            <span className="text-foreground">{label}</span>
            <b className="text-foreground">{value}</b>
        </div>
    );
}

export function TodayScreen({
    planState,
}: Readonly<{ planState: 'has' | 'empty' }>) {
    return (
        <div className="px-4 pt-16 pb-22 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-10">
            {planState === 'has' ? <PlanCard /> : <NoPlanCard />}

            <div className="mb-4">
                <span className="font-mono text-[10px] font-extrabold tracking-[.09em] text-foreground uppercase">
                    you vs past you · last 90 days
                </span>
                <h2 className="mt-2 font-serif text-[25px] leading-[1.14] font-semibold text-icon-accent italic">
                    you&apos;re faster than
                    <br />
                    you were in june.
                </h2>
                <p className="mt-2 text-[13px] leading-[1.5] text-foreground">
                    11 s/km faster on average, across 4 matched runs.
                </p>
                <div className="mt-3 flex flex-col gap-px overflow-hidden rounded-[14px] border border-border-strong bg-border shadow-e1">
                    {EVIDENCE.map((e) => (
                        <EvidenceRow key={e.label} {...e} />
                    ))}
                </div>
            </div>

            <div className="mb-4 rounded-[14px] border border-today-accent bg-card p-4 text-foreground">
                <div className="flex items-center gap-3">
                    <FaceIcon
                        size={42}
                        ring="var(--leaf)"
                        fill="var(--card)"
                        feature="var(--foreground)"
                    />
                    <div>
                        <div className="mb-1 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.1em] text-icon-accent uppercase">
                            today
                        </div>
                        <p className="m-0 font-serif text-[13.5px] leading-[1.5] font-bold italic">
                            legs still owe you from tuesday&apos;s tempo.
                        </p>
                    </div>
                </div>
                <p className="mt-2.5 font-serif text-[12.5px] leading-[1.55] text-foreground italic">
                    keep today easy and let it settle — the build phase rewards
                    patience, not heroics.
                </p>
            </div>

            <Collapsible className="mb-2">
                <CollapsibleTrigger className="group flex w-full items-center justify-between gap-2 rounded-[14px] border border-border-strong bg-card px-3.5 py-3.25 text-xs leading-[1.2] text-foreground shadow-e1">
                    <span>
                        <b className="font-bold text-foreground">
                            this week&apos;s stats
                        </b>{' '}
                        · 4 runs · 18.2 km
                    </span>
                    <ChevronDown
                        className="size-[18px] flex-none text-foreground transition-transform group-aria-expanded:rotate-180"
                        aria-hidden
                    />
                </CollapsibleTrigger>
                <CollapsibleContent className="pt-3">
                    <div className="rounded-[14px] border border-border-strong bg-card p-3.5 shadow-e1">
                        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <StatFigure value="4" label="runs" />
                            <span className="text-foreground">·</span>
                            <StatFigure value="18.2" label="km" />
                            <span className="text-foreground">·</span>
                            <StatFigure value="312" label="trimp" />
                        </div>
                        <div className="my-3 h-px bg-border-strong" />
                        <div className="flex flex-col gap-2.5">
                            <VitalRow
                                label="vibe"
                                value="steady"
                                pct={55}
                                sub="holding rhythm"
                                tone="good"
                            />
                            <VitalRow
                                label="readiness"
                                value="+8"
                                pct={68}
                                sub="right on track"
                                tone="good"
                            />
                            <VitalRow
                                label="recovery"
                                value="14h"
                                pct={35}
                                sub="short turnaround since tuesday"
                                tone="watch"
                            />
                        </div>
                    </div>

                    <div className="mt-2 grid grid-cols-2 gap-2">
                        <div className="rounded-[10px] border border-border-strong bg-card p-3 shadow-e1">
                            <h4 className="mb-2 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase">
                                last run · yesterday
                            </h4>
                            <MiniRow label="km" value="6.2" />
                            <MiniRow label="pace" value="5:32/km" />
                            <MiniRow label="trimp" value="78" />
                            <a
                                href="#"
                                className="mt-2 inline-flex items-center gap-0.75 text-[10.5px] leading-[1.2] text-foreground underline"
                            >
                                view run detail
                                <ArrowRight className="size-3" aria-hidden />
                            </a>
                        </div>
                        <div className="rounded-[10px] border border-border-strong bg-card p-3 shadow-e1">
                            <h4 className="mb-2 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase">
                                condition · 7 days
                            </h4>
                            <MiniRow label="fitness" value="42" />
                            <MiniRow label="fatigue" value="38" />
                            <MiniRow label="strain" value="1.1" />
                            <a
                                href="#"
                                className="mt-2 inline-flex items-center gap-0.75 text-[10.5px] leading-[1.2] text-foreground underline"
                            >
                                technical detail
                                <ArrowRight className="size-3" aria-hidden />
                            </a>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </div>
    );
}
