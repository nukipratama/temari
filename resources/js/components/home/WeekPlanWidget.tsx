import { Link } from '@inertiajs/react';

import type { WeekPlan, WeekPlanDay } from '@/types/inertia';

import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { formatPace, parseNaiveLocalDate, todayLocalIso } from '@/lib/pace';

const SESSION_TYPE_LABEL: Record<string, string> = {
    easy: 'Easy',
    long: 'Long run',
    tempo: 'Tempo',
    interval: 'Interval',
    rest: 'Rest',
};

const PHASE_LABEL: Record<string, string> = {
    base: 'Base',
    build: 'Build',
    peak: 'Peak',
    taper: 'Taper',
    deload: 'Deload',
};

const STATUS_LABEL: Record<string, string> = {
    planned: 'Upcoming',
    done: 'Done',
    partial: 'Partial',
    missed: 'Missed',
    overreached: 'Overreached',
    skip: 'Skipped',
};

/** Same shape-per-intensity vocabulary as the frozen prototype's TodayScreen:
 *  quality/hard days read as a flame, easy/long days as a feather, rest as a
 *  bed. This is the day's `session_type`, independent of how it went. */
const TYPE_ICON: Record<string, string> = {
    tempo: 'mdi:fire',
    interval: 'mdi:fire',
    easy: 'mdi:feather',
    long: 'mdi:feather',
    rest: 'mdi:bed',
};

/** Compliance-v2's six statuses, colored distinctly so "did more than asked"
 *  (overreached) never reads the same as "hit it exactly" (done), and a
 *  `skip` (explicitly excused) never reads as a `missed` (didn't happen). */
const STATUS_TONE: Record<string, string> = {
    done: 'text-leaf-ink',
    partial: 'text-leaf-ink opacity-60',
    overreached: 'text-horizon-ink',
    missed: 'text-ember-ink opacity-40',
    skip: 'text-text-3',
};

const RING_SIZE = 60;
const RING_STROKE = 6;

function weekdayAbbr(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    return date === null
        ? ''
        : date.toLocaleDateString('en-US', { weekday: 'short' });
}

/** Native-tooltip + accessible detail for a day cell: status, the 0-100
 *  compliance score when one exists, and whether a rest day got run anyway. */
function dayDetail(day: WeekPlanDay): string {
    const parts = [STATUS_LABEL[day.status] ?? day.status];
    if (day.compliance_score !== null) {
        parts.push(`${day.compliance_score}%`);
    }
    if (day.ran_anyway) {
        parts.push('Ran anyway');
    }
    return parts.join(' · ');
}

/** The prototype's `ProgressRing`: an arc for credited/total with the same
 *  figure reading out at its centre. */
function ProgressRing({
    credited,
    total,
}: Readonly<{ credited: number; total: number }>) {
    const radius = (RING_SIZE - RING_STROKE) / 2;
    const circumference = 2 * Math.PI * radius;
    const ratio = total > 0 ? Math.min(1, Math.max(0, credited / total)) : 0;
    const tweenedRatio = useCountUp(ratio);
    const tweenedCredited = useCountUp(credited);

    return (
        <div
            className="relative flex-none"
            style={{ width: RING_SIZE, height: RING_SIZE }}
        >
            <svg
                width={RING_SIZE}
                height={RING_SIZE}
                viewBox={`0 0 ${RING_SIZE} ${RING_SIZE}`}
                className="-rotate-90"
                aria-hidden
            >
                <circle
                    cx={RING_SIZE / 2}
                    cy={RING_SIZE / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={RING_STROKE}
                    className="stroke-border"
                />
                <circle
                    cx={RING_SIZE / 2}
                    cy={RING_SIZE / 2}
                    r={radius}
                    fill="none"
                    strokeWidth={RING_STROKE}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={circumference * (1 - tweenedRatio)}
                    className="stroke-icon-accent"
                />
            </svg>
            <span className="absolute inset-0 flex items-center justify-center font-mono text-[11px] font-extrabold tabular-nums text-foreground">
                {Math.round(tweenedCredited)}/{total}
            </span>
        </div>
    );
}

function PlanFigure({
    value,
    label,
}: Readonly<{ value: string; label: string }>) {
    return (
        <div>
            <b className="block font-mono text-[15px] font-extrabold tabular-nums text-foreground">
                {value}
            </b>
            <span className="text-[9px] uppercase tracking-[0.05em] text-foreground">
                {label}
            </span>
        </div>
    );
}

/** A rest day someone ran anyway reads as the distance they actually ran, the
 *  way the prototype's own wednesday cell does. */
function distanceLabel(day: WeekPlanDay): string {
    if (day.session_type !== 'rest') {
        return `${day.distance_km}k`;
    }
    return day.ran_anyway && day.actual_km !== null
        ? `${day.actual_km}k`
        : 'rest';
}

function DayCell({
    day,
    isToday,
}: Readonly<{ day: WeekPlanDay; isToday: boolean }>) {
    const isRest = day.session_type === 'rest';
    let tone = STATUS_TONE[day.status] ?? 'text-foreground';
    if (isRest) {
        tone = day.ran_anyway ? 'text-leaf-ink' : 'text-foreground';
    }

    return (
        <li
            title={dayDetail(day)}
            className={cn(
                'flex flex-col items-center gap-0.5 rounded-lg py-1.5',
                isToday && 'ring-[1.5px] ring-inset ring-icon-accent',
            )}
        >
            <span className="font-mono text-[9px] uppercase tracking-[0.05em] text-foreground">
                {weekdayAbbr(day.date)}
            </span>
            <Icon
                icon={TYPE_ICON[day.session_type] ?? 'mdi:fire'}
                width={13}
                height={13}
                className={tone}
                aria-hidden
            />
            <span className="font-mono text-[8px] text-foreground">
                {distanceLabel(day)}
            </span>
        </li>
    );
}

/**
 * "This week's plan" — the widget Today leads with, on the prototype's
 * `PlanCard` shape: phase badge, a credited/total ring beside two figures, a
 * seven-day grid, and a footer row for today's session. Fields are exactly
 * `CurrentWeekPlanBuilder::forUser()`'s shape, the same computation Plan's own
 * week rows use, so nothing shown here can drift from Plan.
 */
export default function WeekPlanWidget({
    weekPlan,
}: Readonly<{ weekPlan: WeekPlan }>) {
    const todayIso = todayLocalIso();
    const today = weekPlan.days.find((d) => d.date === todayIso) ?? null;
    const todayCorePaceSecPerKm =
        today?.segments.find((s) => s.key === 'main' || s.key === 'interval')
            ?.pace_sec_per_km ?? null;
    const kmTweened = useCountUp(weekPlan.planned_km_this_week);

    return (
        <Card as="section">
            <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2">
                <Eyebrow token="micro" className="text-foreground">
                    This week&apos;s plan
                </Eyebrow>
                <Chip className="bg-muted text-foreground">
                    {PHASE_LABEL[weekPlan.phase] ?? weekPlan.phase}
                </Chip>
            </div>

            <div className="mb-3.5 flex items-center gap-4 min-[900px]:gap-6">
                <ProgressRing
                    credited={weekPlan.credited_this_week}
                    total={weekPlan.sessions_per_week}
                />
                <div className="grid flex-1 grid-cols-2 gap-2">
                    <PlanFigure
                        value={`${weekPlan.credited_this_week}/${weekPlan.sessions_per_week}`}
                        label="Sessions"
                    />
                    <PlanFigure
                        value={kmTweened.toFixed(1)}
                        label="Km this week"
                    />
                </div>
            </div>

            <ul className="mb-3.5 grid grid-cols-7 gap-1">
                {weekPlan.days.map((day) => (
                    <DayCell
                        key={day.date}
                        day={day}
                        isToday={day.date === todayIso}
                    />
                ))}
            </ul>

            {today !== null && (
                <Link
                    href="/plan"
                    className="focus-ring flex items-center justify-between gap-2 rounded-lg bg-muted px-3 py-2.5 text-[11.5px] text-foreground transition-colors hover:bg-accent"
                >
                    <span>
                        <b>Today</b> ·{' '}
                        {SESSION_TYPE_LABEL[today.session_type] ??
                            today.session_type}
                        {today.session_type !== 'rest' &&
                            ` · ${today.distance_km} km`}
                        {todayCorePaceSecPerKm !== null &&
                            ` · ${formatPace(todayCorePaceSecPerKm)}/km`}
                        {today.clamp_note !== null && (
                            <span className="mt-1 block italic text-text-3">
                                {today.clamp_note}
                            </span>
                        )}
                    </span>
                    <Icon
                        icon="mdi:chevron-right"
                        width={16}
                        height={16}
                        className="flex-none text-foreground"
                        aria-hidden
                    />
                </Link>
            )}
        </Card>
    );
}
