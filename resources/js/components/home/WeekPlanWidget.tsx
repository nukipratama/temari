import { Link } from '@inertiajs/react';
import { Bed, ChevronRight, Feather, Flag, Flame } from 'lucide-react';

import type { WeekPlan, WeekPlanDay, WeeklySnapshot } from '@/types/inertia';

import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon, IconComponent } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { parseNaiveLocalDate, todayLocalIso } from '@/lib/pace';

const PHASE_LABEL: Record<string, string> = {
    base: 'base',
    build: 'build',
    peak: 'peak',
    taper: 'taper',
    deload: 'deload',
};

const STATUS_LABEL: Record<string, string> = {
    planned: 'upcoming',
    done: 'done',
    partial: 'partial',
    missed: 'missed',
    overreached: 'overreached',
    skip: 'skipped',
};

/** Same shape-per-intensity vocabulary as the frozen prototype's TodayScreen:
 *  quality/hard days read as a flame, easy/long days as a feather, rest as a
 *  bed, and the goal race as the chequered flag it is. This is the day's
 *  `session_type`, independent of how it went. */
const TYPE_ICON: Record<string, IconComponent> = {
    tempo: Flame,
    interval: Flame,
    easy: Feather,
    long: Feather,
    rest: Bed,
    race: Flag,
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
    if (day.session_type !== 'rest') {
        parts.push(`planned ${day.distance_km}k`);
    }
    if (day.actual_km !== null) {
        parts.push(`ran ${day.actual_km}k`);
    }
    if (day.compliance_score !== null) {
        parts.push(`${day.compliance_score}%`);
    }
    if (day.ran_anyway) {
        parts.push('ran anyway');
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
            <span className="absolute inset-0 flex items-center justify-center font-mono text-[0.6875rem] font-extrabold tabular-nums text-foreground">
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
            <b className="block font-mono text-[0.9375rem] font-extrabold tabular-nums text-foreground">
                {value}
            </b>
            <span className="font-mono text-[0.5625rem] uppercase tracking-[0.05em] text-foreground">
                {label}
            </span>
        </div>
    );
}

function DayCell({
    day,
    isToday,
    hasElapsed,
}: Readonly<{ day: WeekPlanDay; isToday: boolean; hasElapsed: boolean }>) {
    const isRest = day.session_type === 'rest';
    let tone = STATUS_TONE[day.status] ?? 'text-foreground';
    if (isRest) {
        tone = day.ran_anyway ? 'text-leaf-ink' : 'text-foreground';
    }

    const ran = hasElapsed && day.actual_km !== null;

    return (
        <li
            title={dayDetail(day)}
            className={cn(
                'flex flex-col items-center gap-0.5 rounded-lg py-1.5',
                isToday && 'ring-[1.5px] ring-inset ring-icon-accent',
            )}
        >
            <span className="font-mono text-[0.5625rem] uppercase tracking-[0.05em] text-foreground">
                {weekdayAbbr(day.date)}
            </span>
            <Icon
                icon={TYPE_ICON[day.session_type] ?? Flame}
                width={13}
                height={13}
                className={tone}
                aria-hidden
            />
            {ran ? (
                <>
                    <span
                        className={cn(
                            'font-mono text-[0.5625rem] font-bold',
                            tone,
                        )}
                    >
                        {day.actual_km}k
                    </span>
                    {!isRest && (
                        <span className="font-mono text-[0.5rem] leading-none text-text-2">
                            of {day.distance_km}k
                        </span>
                    )}
                </>
            ) : (
                <span className="font-mono text-[0.5rem] text-foreground">
                    {isRest ? 'rest' : `${day.distance_km}k`}
                </span>
            )}
        </li>
    );
}

/**
 * "This week's plan" — the week at a glance, on the prototype's `PlanCard`
 * shape: phase badge, a credited/total ring beside the week's actual km
 * against its planned km and its TRIMP, a seven-day grid, and a link into
 * Plan. Today's own session is stated once, on `TodaySession`, beside the
 * voice describing it. Plan fields are exactly
 * `CurrentWeekPlanBuilder::forUser()`'s shape, the same computation Plan's own
 * week rows use, so nothing shown here can drift from Plan; the actuals are
 * the week's `WeeklySnapshot`.
 */
export default function WeekPlanWidget({
    weekPlan,
    snapshot,
}: Readonly<{ weekPlan: WeekPlan; snapshot: WeeklySnapshot | null }>) {
    const todayIso = todayLocalIso();
    const actualKm = snapshot?.distance_km ?? 0;
    const actualTweened = useCountUp(actualKm);
    const plannedTweened = useCountUp(weekPlan.planned_km_this_week);
    const trimpTweened = useCountUp(snapshot?.weekly_trimp ?? 0);

    const kmValue = `${actualKm === 0 ? '0' : actualTweened.toFixed(1)} of ${plannedTweened.toFixed(1)}`;
    const trimpValue =
        snapshot?.weekly_trimp != null
            ? Math.round(trimpTweened).toString()
            : '—';

    return (
        <Card as="section">
            <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2">
                <Eyebrow token="micro" className="text-foreground">
                    this week&apos;s plan
                </Eyebrow>
                <Chip className="text-label-micro bg-muted text-foreground">
                    {PHASE_LABEL[weekPlan.phase] ?? weekPlan.phase}
                </Chip>
            </div>

            <div className="mb-3.5 flex items-center gap-4 min-[900px]:gap-6">
                <div className="flex flex-none flex-col items-center gap-1">
                    <ProgressRing
                        credited={weekPlan.credited_this_week}
                        total={weekPlan.sessions_this_week}
                    />
                    <span className="font-mono text-[0.5625rem] uppercase tracking-[0.05em] text-foreground">
                        sessions
                    </span>
                </div>
                <div className="flex flex-1 items-center justify-center gap-6 px-2 text-center min-[900px]:gap-10">
                    <PlanFigure value={kmValue} label="km" />
                    <PlanFigure value={trimpValue} label="trimp" />
                </div>
            </div>

            <ul className="mb-3.5 grid grid-cols-7 gap-1">
                {weekPlan.days.map((day) => (
                    <DayCell
                        key={day.date}
                        day={day}
                        isToday={day.date === todayIso}
                        hasElapsed={day.date <= todayIso}
                    />
                ))}
            </ul>

            <Link
                href="/plan"
                className="focus-ring flex items-center justify-between gap-2 rounded-lg bg-muted px-3 py-2.5 text-[0.71875rem] text-foreground transition-colors hover:bg-accent"
            >
                <span>see the plan</span>
                <Icon
                    icon={ChevronRight}
                    width={16}
                    height={16}
                    className="flex-none text-foreground"
                    aria-hidden
                />
            </Link>
        </Card>
    );
}
