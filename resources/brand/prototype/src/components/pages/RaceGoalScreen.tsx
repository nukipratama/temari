import { Flag, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

import { FaceIcon } from '@/components/FaceIcon';
import { useCountUp } from '@/hooks/useCountUp';
import {
    CONFIDENCE_COPY,
    MOCK_PROJECTION,
    MOCK_RACE,
    type Projection,
} from '@/lib/raceProgress';
import { cn } from '@/lib/utils';

import { AiReplanPill } from './AiReplanPill';
import { ScheduleRaceTabs } from './ScheduleRaceTabs';

const DISTANCE_PRESETS = [
    { label: '5K', km: 5 },
    { label: '10K', km: 10 },
    { label: 'half', km: 21.1 },
    { label: 'marathon', km: 42.2 },
] as const;

// A pace floor a touch below current world-record pace (~2:31–2:51/km
// depending on distance) — not personalized to the athlete, just a sanity
// check that the numbers are physically plausible for anyone.
const IMPOSSIBLE_PACE_SEC_PER_KM = 155;

// How much faster than your own best-case (low_sec) projection counts as
// "significantly more ambitious than your data supports" — not impossible,
// just a real stretch worth a gut check.
const PERSONALIZED_STRETCH_RATIO = 0.9;

function formatPace(secPerKm: number): string {
    const m = Math.floor(secPerKm / 60);
    const s = Math.round(secPerKm % 60);
    return `${m}:${String(s).padStart(2, '0')}`;
}

function formatDurationHMS(totalSec: number): string {
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = Math.round(totalSec % 60);
    return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function PillOption({
    label,
    active,
    onClick,
}: Readonly<{ label: string; active: boolean; onClick: () => void }>) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'rounded-full border px-3.5 py-2 font-sans text-[12px] leading-[1.2] font-bold',
                active
                    ? 'border-icon-accent bg-horizon/20 text-icon-accent'
                    : 'border-border-strong text-foreground',
            )}
        >
            {label}
        </button>
    );
}

function StatTile({
    value,
    label,
}: Readonly<{ value: string; label: string }>) {
    return (
        <div>
            <b className="block font-mono text-[17px] leading-[1.2] font-extrabold text-foreground">
                {value}
            </b>
            <span className="font-mono text-[9px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                {label}
            </span>
        </div>
    );
}

function RaceCard({
    name,
    date,
    daysToGo,
    distanceKm,
    goalTimeSec,
}: Readonly<{
    name: string;
    date: string;
    daysToGo: number;
    distanceKm: number;
    goalTimeSec: number;
}>) {
    return (
        <div className="mb-3 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="flex items-center gap-2">
                <Flag
                    className="size-[15px] flex-none text-icon-accent"
                    aria-hidden
                />
                <b className="text-[13px] leading-[1.2] font-bold text-foreground">
                    {name}
                </b>
            </div>
            <div className="mt-1 font-mono text-[10px] leading-[1.2] text-foreground">
                {date} · {daysToGo} days to go
            </div>
            <div className="mt-3 flex gap-6">
                <StatTile
                    value={`${distanceKm.toFixed(1)} km`}
                    label="distance"
                />
                <StatTile
                    value={formatDurationHMS(goalTimeSec)}
                    label="goal time"
                />
            </div>
        </div>
    );
}

function ProjectionGauge({ projection }: Readonly<{ projection: Projection }>) {
    const { lowSec, predictedSec, highSec } = projection;
    const span = highSec - lowSec || 1;
    const ratio = Math.min(1, Math.max(0, (predictedSec - lowSec) / span));
    const tweenedRatio = useCountUp(ratio);
    const cx = 70;
    const cy = 70;
    const r = 58;
    const angleRad = ((180 - tweenedRatio * 180) * Math.PI) / 180;
    const markerX = cx + r * Math.cos(angleRad);
    const markerY = cy - r * Math.sin(angleRad);
    const arcPath = `M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`;

    return (
        <div className="flex flex-col items-center">
            <svg width={140} height={78} viewBox="0 0 140 78" aria-hidden>
                <path
                    d={arcPath}
                    pathLength={100}
                    fill="none"
                    strokeWidth={10}
                    strokeLinecap="round"
                    className="stroke-border-strong"
                />
                <path
                    d={arcPath}
                    pathLength={100}
                    fill="none"
                    strokeWidth={10}
                    strokeLinecap="round"
                    strokeDasharray={`${tweenedRatio * 100} 100`}
                    className="stroke-icon-accent"
                />
                <circle
                    cx={markerX}
                    cy={markerY}
                    r={5}
                    strokeWidth={3}
                    className="fill-card stroke-icon-accent"
                />
            </svg>
            <div className="relative mt-1.5 h-3.5 w-[140px] font-mono text-[9px] leading-[1.2] font-bold text-foreground">
                <span className="absolute left-[8.6%] -translate-x-1/2">
                    {formatDurationHMS(lowSec)}
                </span>
                <span className="absolute right-[8.6%] translate-x-1/2">
                    {formatDurationHMS(highSec)}
                </span>
            </div>
        </div>
    );
}

function ProjectionBlock({
    projection,
}: Readonly<{ projection: Projection | null }>) {
    if (!projection) {
        return (
            <div className="mb-3 rounded-[14px] border border-border-strong bg-card p-4 text-[11.5px] leading-[1.5] text-foreground shadow-e1">
                no personal record yet to project a finish time from. set one on
                a run and it shows up here.
            </div>
        );
    }
    const prLabel =
        projection.prCount === 1 ? '1 pr' : `${projection.prCount} prs`;
    return (
        <div className="relative mb-3 overflow-hidden rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div
                aria-hidden
                className="pointer-events-none absolute -top-10 -right-10 size-[160px] rounded-full blur-[30px]"
                style={{
                    background:
                        'radial-gradient(circle, color-mix(in oklab, var(--horizon) 45%, transparent) 0%, color-mix(in oklab, var(--horizon) 18%, transparent) 45%, transparent 70%)',
                }}
            />
            <div className="relative flex items-center gap-1.5">
                <span className="font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase">
                    projected finish
                </span>
                <FaceIcon
                    size={18}
                    ring="var(--horizon)"
                    fill="var(--card)"
                    feature="var(--foreground)"
                />
            </div>
            <div className="relative mt-2 flex justify-center">
                <ProjectionGauge projection={projection} />
            </div>
            <div className="relative mt-1 text-center font-mono text-xl leading-[1.2] font-extrabold text-icon-accent">
                {formatDurationHMS(projection.predictedSec)}
            </div>
            <p className="relative m-0 mt-1.5 text-center text-[11px] leading-[1.5] text-foreground">
                best estimate, based on {prLabel} (
                {CONFIDENCE_COPY[projection.confidence]}).
            </p>
        </div>
    );
}

function NoRaceState() {
    return (
        <div className="mb-3 flex flex-col items-center gap-3 rounded-[14px] border border-border-strong bg-card px-6 py-8 text-center shadow-e1">
            <FaceIcon
                size={40}
                ring="var(--horizon)"
                fill="var(--card)"
                feature="var(--foreground)"
            />
            <p className="m-0 font-serif text-base leading-[1.2] font-semibold text-foreground italic">
                no race on the calendar yet.
            </p>
            <p className="m-0 max-w-[220px] text-xs leading-[1.5] text-foreground">
                set one below and temari will start projecting your finish time.
            </p>
        </div>
    );
}

function RaceGoalForm({
    raceState,
    projection,
    aiReplanState,
    onTriggerAiReplan,
}: Readonly<{
    raceState: 'unset' | 'set';
    projection: Projection | null;
    aiReplanState: 'ready' | 'cooldown';
    onTriggerAiReplan: () => void;
}>) {
    const [distanceKm, setDistanceKm] = useState(21.1);
    const [hours, setHours] = useState(1);
    const [minutes, setMinutes] = useState(50);
    const [seconds, setSeconds] = useState(0);

    const goalTimeSec = hours * 3600 + minutes * 60 + seconds;
    const paceSecPerKm = distanceKm > 0 ? goalTimeSec / distanceKm : 0;
    const paceIsImplausible =
        goalTimeSec > 0 &&
        paceSecPerKm > 0 &&
        paceSecPerKm < IMPOSSIBLE_PACE_SEC_PER_KM;

    // Only meaningful when the form's distance still matches the distance
    // the projection was actually computed for — a fake regression can't
    // recompute itself for an arbitrary custom distance in a static mockup.
    const projectionApplies =
        projection != null &&
        Math.abs(distanceKm - projection.distanceKm) < 0.01;
    const isMoreAmbitiousThanPr =
        projectionApplies &&
        goalTimeSec > 0 &&
        goalTimeSec < (projection?.lowSec ?? 0) * PERSONALIZED_STRETCH_RATIO;
    // The universal impossible-pace check takes precedence — no point
    // telling someone their goal is "ambitious" when it's not humanly
    // achievable in the first place.
    const showPersonalizedWarning = !paceIsImplausible && isMoreAmbitiousThanPr;

    return (
        <div className="rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-3.5 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                {raceState === 'set' ? 'edit your race' : 'set your race'}
            </div>
            <div className="flex flex-col gap-3.5">
                <label className="block font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    name (optional)
                    <input
                        type="text"
                        placeholder="jakarta half 2026"
                        className="mt-1.25 block w-full rounded-[10px] border border-border-strong bg-muted px-2.5 py-2.25 font-sans text-[13px] font-semibold text-foreground normal-case placeholder:text-foreground"
                    />
                </label>
                <label className="block font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                    race day
                    <input
                        type="date"
                        className="mt-1.25 block w-full rounded-[10px] border border-border-strong bg-muted px-2.5 py-2.25 font-mono text-[13px] font-bold text-foreground"
                    />
                </label>
                <div>
                    <span className="font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                        distance
                    </span>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {DISTANCE_PRESETS.map((p) => (
                            <PillOption
                                key={p.label}
                                label={p.label}
                                active={distanceKm === p.km}
                                onClick={() => setDistanceKm(p.km)}
                            />
                        ))}
                    </div>
                    <label className="mt-1.5 flex items-center gap-1.5 font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                        custom
                        <input
                            type="number"
                            step="0.1"
                            value={distanceKm}
                            onChange={(e) =>
                                setDistanceKm(Number(e.target.value))
                            }
                            aria-label="custom distance in km"
                            className="w-20 rounded-[10px] border border-border-strong bg-muted px-2.5 py-1.5 font-mono text-[12px] font-bold text-foreground normal-case"
                        />
                        km
                    </label>
                </div>
                <div>
                    <span className="font-mono text-[9px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                        goal time
                    </span>
                    <div className="mt-1.5 flex items-center gap-1.5">
                        <input
                            type="number"
                            min={0}
                            max={71}
                            value={hours}
                            onChange={(e) => setHours(Number(e.target.value))}
                            aria-label="hours"
                            className="w-14 rounded-[10px] border border-border-strong bg-muted px-2 py-2 text-center font-mono text-[13px] font-bold text-foreground"
                        />
                        <span className="text-[11px] text-foreground">hr</span>
                        <input
                            type="number"
                            min={0}
                            max={59}
                            value={minutes}
                            onChange={(e) => setMinutes(Number(e.target.value))}
                            aria-label="minutes"
                            className="w-14 rounded-[10px] border border-border-strong bg-muted px-2 py-2 text-center font-mono text-[13px] font-bold text-foreground"
                        />
                        <span className="text-[11px] text-foreground">min</span>
                        <input
                            type="number"
                            min={0}
                            max={59}
                            value={seconds}
                            onChange={(e) => setSeconds(Number(e.target.value))}
                            aria-label="seconds"
                            className="w-14 rounded-[10px] border border-border-strong bg-muted px-2 py-2 text-center font-mono text-[13px] font-bold text-foreground"
                        />
                        <span className="text-[11px] text-foreground">sec</span>
                    </div>
                    {paceIsImplausible && (
                        <div className="mt-2 flex items-start gap-1.5 rounded-[10px] bg-[color-mix(in_oklab,#d97706_14%,transparent)] px-2.5 py-2 text-[11px] leading-[1.4] text-foreground">
                            <TriangleAlert
                                className="mt-0.25 size-3.5 flex-none text-[#d97706]"
                                aria-hidden
                            />
                            <span>
                                that&apos;s {formatPace(paceSecPerKm)}/km —
                                quicker than world-record pace for most
                                distances. worth double-checking, but you can
                                still save it.
                            </span>
                        </div>
                    )}
                    {showPersonalizedWarning && projection && (
                        <div className="mt-2 flex items-start gap-1.5 rounded-[10px] bg-citrus/15 px-2.5 py-2 text-[11px] leading-[1.4] text-foreground">
                            <TriangleAlert
                                className="mt-0.25 size-3.5 flex-none text-citrus-ink"
                                aria-hidden
                            />
                            <span>
                                that&apos;s well ahead of your own projected
                                range ({formatDurationHMS(projection.lowSec)}–
                                {formatDurationHMS(projection.highSec)}) —
                                ambitious, but you can still save it.
                            </span>
                        </div>
                    )}
                </div>
            </div>
            {aiReplanState === 'cooldown' ? (
                <div className="mt-4 flex justify-center">
                    <AiReplanPill />
                </div>
            ) : (
                <a
                    href="#"
                    onClick={(e) => {
                        e.preventDefault();
                        onTriggerAiReplan();
                    }}
                    className="mt-4 flex items-center justify-center rounded-full bg-btn-primary-bg py-3 font-sans text-sm font-bold text-btn-primary-fg no-underline outline-none focus-visible:ring-3 focus-visible:ring-ring/30"
                >
                    {raceState === 'set' ? 'update race' : 'set race'}
                </a>
            )}
        </div>
    );
}

export function RaceGoalScreen({
    raceState,
    projectionState = 'ready',
    aiReplanState,
    onTriggerAiReplan,
    onNavigateSchedule,
}: Readonly<{
    raceState: 'unset' | 'set';
    projectionState?: 'ready' | 'none';
    aiReplanState: 'ready' | 'cooldown';
    onTriggerAiReplan: () => void;
    onNavigateSchedule: () => void;
}>) {
    const projection =
        raceState === 'set' && projectionState === 'ready'
            ? MOCK_PROJECTION
            : null;

    return (
        <div className="px-4 pt-16 pb-22 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-24">
            <div className="mt-3 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.12em] text-foreground uppercase">
                race
            </div>
            <div className="mt-2 mb-4">
                <h1 className="m-0 font-serif text-[24px] leading-[1.15] font-semibold text-foreground italic">
                    {raceState === 'set' ? (
                        <>
                            your race,
                            <br />
                            <em className="text-icon-accent">
                                on the calendar.
                            </em>
                        </>
                    ) : (
                        <>
                            give the plan
                            <br />
                            <em className="text-icon-accent">
                                something to aim at.
                            </em>
                        </>
                    )}
                </h1>
                <p className="m-0 mt-2 text-xs leading-[1.55] text-foreground">
                    set a race and temari projects a realistic finish time from
                    your own prs, then tracks your fitness trend against it.
                </p>
            </div>

            <ScheduleRaceTabs
                active="race"
                onNavigate={(tab) => tab === 'schedule' && onNavigateSchedule()}
            />

            {raceState === 'unset' && <NoRaceState />}
            {raceState === 'set' && (
                <>
                    <RaceCard {...MOCK_RACE} />
                    <ProjectionBlock projection={projection} />
                </>
            )}

            <div className="mt-3">
                <RaceGoalForm
                    raceState={raceState}
                    projection={projection}
                    aiReplanState={aiReplanState}
                    onTriggerAiReplan={onTriggerAiReplan}
                />
            </div>
        </div>
    );
}
