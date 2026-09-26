import { Link } from '@inertiajs/react';
import { Bed, ChevronRight, Feather, Flag, Flame } from 'lucide-react';

import type { WeekPlan, WeekPlanDay, WeeklySnapshot } from '@/types/inertia';

import { ChangeRow } from '@/components/plan/DeltaPair';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon, IconComponent } from '@/components/ui/Icon';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { formatKm, parseNaiveLocalDate, todayLocalIso } from '@/lib/pace';
import { deltaDirection, ranHot } from '@/lib/plan';

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
    hot: 'ran hot',
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

const kmFigure = (km: number | null): string =>
    formatKm(km === null ? null : km * 1000, 1);

function weekdayAbbr(iso: string): string {
    const date = parseNaiveLocalDate(iso);
    return date === null
        ? ''
        : date.toLocaleDateString('en-US', { weekday: 'short' });
}

/** Native-tooltip + accessible detail for a day cell: status, the 0-100
 *  compliance score when one exists, and whether a rest day got run anyway. */
function dayDetail(day: WeekPlanDay): string {
    const status = ranHot(day) ? 'hot' : day.status;
    const parts = [STATUS_LABEL[status] ?? status];
    if (day.session_type !== 'rest') {
        parts.push(`planned ${kmFigure(day.distance_km)} km`);
    }
    if (day.actual_km !== null) {
        parts.push(`ran ${kmFigure(day.actual_km)} km`);
    }
    if (day.compliance_score !== null) {
        parts.push(`${day.compliance_score}%`);
    }
    if (day.ran_anyway) {
        parts.push('ran anyway');
    }
    return parts.join(' · ');
}

function StatTile({
    label,
    value,
}: Readonly<{ label: string; value: string }>) {
    return (
        <div className="rounded-sm bg-secondary px-3 py-2.5">
            <dt className="text-label-micro text-text-3">{label}</dt>
            <dd className="text-stat-sm mt-1">{value}</dd>
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
        <li title={dayDetail(day)}>
            <Link
                href={`/plan?day=${day.date}`}
                aria-label={`${weekdayAbbr(day.date)} · ${dayDetail(day)}`}
                className={cn(
                    'focus-ring flex flex-col items-center gap-0.5 rounded-lg py-1.5 transition-colors hover:bg-muted',
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
                            {kmFigure(day.actual_km)} km
                        </span>
                        {!isRest && (
                            <span className="font-mono text-[0.5rem] leading-none text-text-2">
                                of {kmFigure(day.distance_km)}
                            </span>
                        )}
                    </>
                ) : (
                    <span className="font-mono text-[0.5rem] text-foreground">
                        {isRest ? 'rest' : `${kmFigure(day.distance_km)} km`}
                    </span>
                )}
            </Link>
        </li>
    );
}

/**
 * "This week's plan" — the week at a glance: phase badge, the week's actual km
 * against its planned km as the hero number, credited sessions and TRIMP as
 * tiles, a seven-day grid, and a link into Plan. Today's own session is stated once, on `TodaySession`, beside the
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

    const creditedTweened = useCountUp(weekPlan.credited_this_week);
    const actualValue = actualKm === 0 ? '0' : actualTweened.toFixed(1);
    const trimpValue =
        snapshot?.weekly_trimp != null
            ? Math.round(trimpTweened).toString()
            : '—';

    return (
        <section>
            <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2">
                <Eyebrow token="micro" className="text-foreground">
                    this week&apos;s plan
                </Eyebrow>
                <Chip className="text-label-micro bg-muted text-foreground">
                    {PHASE_LABEL[weekPlan.phase] ?? weekPlan.phase}
                </Chip>
            </div>

            <p className="flex flex-wrap items-baseline gap-x-1.5">
                <span className="text-stat">{actualValue}</span>
                <span className="text-meta">
                    / {plannedTweened.toFixed(1)} km
                </span>
            </p>
            {weekPlan.planned_km_eased_from !== null && (
                <ChangeRow
                    className="mt-1 font-mono text-label-micro text-text-2"
                    label="km"
                    from={weekPlan.planned_km_eased_from.toFixed(1)}
                    to={weekPlan.planned_km_this_week.toFixed(1)}
                    direction={deltaDirection(
                        weekPlan.planned_km_eased_from,
                        weekPlan.planned_km_this_week,
                    )}
                    tag="eased"
                />
            )}

            <dl className="mt-3 mb-3.5 grid grid-cols-2 gap-2">
                <StatTile
                    label="sessions"
                    value={`${Math.round(creditedTweened)}/${weekPlan.sessions_this_week}`}
                />
                <StatTile label="trimp" value={trimpValue} />
            </dl>

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
        </section>
    );
}
