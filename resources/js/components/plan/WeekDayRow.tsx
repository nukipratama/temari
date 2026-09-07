import { Link } from '@inertiajs/react';
import { useState } from 'react';

import type { PlanDay } from '@/lib/plan';
import type { AnalysisPayload, PlanDayClamp } from '@/types/inertia';

import MiniSessionBar, { zoneColor } from '@/components/plan/MiniSessionBar';
import SessionBarGraph from '@/components/plan/SessionBarGraph';
import TemariTake from '@/components/plan/TemariTake';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatPace } from '@/lib/pace';
import {
    clampSummary,
    SESSION_TYPE_ICON,
    SESSION_TYPE_LABEL,
    STATUS_LABEL,
    STATUS_TONE,
    weekdayLabel,
} from '@/lib/plan';
import { cardVariants } from '@/lib/variants';

function paceLabel(day: PlanDay): string | null {
    const core = day.segments.find(
        (s) => s.key === 'main' || s.key === 'interval',
    );
    return core?.pace_sec_per_km == null
        ? null
        : `${formatPace(core.pace_sec_per_km)}/km`;
}

/**
 * The whole day, for a rest day that was run anyway. Both halves are day
 * totals: mixing a summed distance with one run's clock is the bug this
 * replaced.
 */
/**
 * A day the plan has already judged states both facts it recorded: what it
 * asked for, and what was run. Every other day shows the ask alone, sized
 * against the athlete's fitness today.
 */
function kmLabel(day: PlanDay): string {
    if (day.prescribed_km == null) {
        return `${day.distance_km} km`;
    }

    return `${day.actual_km ?? 0} of ${day.prescribed_km} km`;
}

function daySummary(day: PlanDay): string {
    const km = day.actual_km == null ? null : `${day.actual_km} km`;
    const seconds = day.activities.reduce<number | null>(
        (total, run) =>
            run.seconds == null ? total : (total ?? 0) + run.seconds,
        null,
    );
    const time = seconds == null ? null : formatDurationHMS(seconds);
    return [km, time].filter((part) => part !== null).join(' · ');
}

/**
 * One run's own distance and duration. Never the day's total paired with a
 * single run's clock — a 5 km and a 7 km session read as one impossible
 * 12 km in 55 minutes that way.
 */
function runSummary(run: PlanDay['activities'][number]): string {
    const time = run.seconds == null ? null : formatDurationHMS(run.seconds);
    return [`${run.km} km`, time].filter((part) => part !== null).join(' · ');
}

/**
 * The readiness step-down, rendered beneath the day's own prescription rather
 * than replacing it. The plan still asks for what it asked for; this is the
 * eased version offered for today, and the note says why.
 */
function ClampStepDown({
    clamp,
    plannedKm,
}: Readonly<{ clamp: PlanDayClamp; plannedKm: number }>) {
    return (
        <div className="mt-2 border-l-2 border-border-strong pl-3">
            <p className="flex items-center gap-1.5 text-label-micro text-text-2">
                <Icon icon="mdi:arrow-down" className="size-3" aria-hidden />
                eased today
            </p>
            <p className="mt-0.5 text-xs font-semibold text-foreground">
                {clampSummary(clamp, plannedKm)}
            </p>
            <p className="mt-1 text-xs italic text-text-2">{clamp.note}</p>
        </div>
    );
}

/** The zone the day's hardest segment sits in, which colours its type icon. */
function iconColor(day: PlanDay): string {
    if (day.session_type === 'rest') {
        return day.ran_anyway ? 'var(--color-leaf)' : 'var(--color-text-3)';
    }
    const zones = day.segments.map((s) => s.zone).sort();
    return zones.length === 0
        ? 'var(--color-text-3)'
        : zoneColor(zones[zones.length - 1]);
}

/**
 * One day of a week, collapsed to weekday + session + a zone strip, expanding
 * to Temari's read on it, the session's segment breakdown, a link to what was
 * actually run, and — on a day still ahead — the move and skip actions.
 */
export default function WeekDayRow({
    day,
    weekDays,
    today,
    narration,
    onMove,
    onSkip,
}: Readonly<{
    day: PlanDay;
    weekDays: PlanDay[];
    today: string;
    narration: AnalysisPayload | null;
    onMove: (toDate: string) => void;
    onSkip: () => void;
}>) {
    const [picking, setPicking] = useState(false);

    const isRest = day.session_type === 'rest';
    const ranAnyway = isRest && day.ran_anyway;
    const editable = day.date > today;
    // A day excused before it passes is still `planned` server-side until
    // plan:score-compliance runs the next morning; the row says "skipped" now.
    const status = day.skipped ? 'skip' : day.status;

    const isValidMoveTarget = (target: PlanDay) =>
        target.date !== day.date &&
        target.date > today &&
        target.session_type === 'rest';

    const canMove = editable && !isRest && weekDays.some(isValidMoveTarget);
    const canSkip = editable && !isRest && !day.skipped;

    return (
        <Collapsible
            className={cn(
                cardVariants({ padding: 'none' }),
                'overflow-hidden',
                day.date === today
                    ? 'border-icon-accent'
                    : 'border-border-strong',
            )}
        >
            <CollapsibleTrigger className="group focus-ring flex w-full items-center gap-3 px-4 py-3 text-left">
                <span className="flex w-9 flex-none flex-col items-center gap-1">
                    <span className="text-label-micro text-text-2">
                        {weekdayLabel(day.date)}
                    </span>
                    <Icon
                        icon={
                            SESSION_TYPE_ICON[day.session_type] ?? 'mdi:feather'
                        }
                        className="size-3.5"
                        style={{ color: iconColor(day) }}
                        aria-hidden
                    />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-semibold text-foreground">
                        {SESSION_TYPE_LABEL[day.session_type] ??
                            day.session_type}
                    </span>
                    {!isRest && (
                        <span className="mt-0.5 block text-xs text-text-2">
                            {kmLabel(day)}
                            {paceLabel(day) && ` · ${paceLabel(day)}`}
                        </span>
                    )}
                    {ranAnyway && (
                        <span className="mt-0.5 block text-xs font-semibold text-leaf-ink">
                            Ran anyway · {daySummary(day)}
                        </span>
                    )}
                    <MiniSessionBar segments={day.segments} />
                    {!isRest && STATUS_LABEL[status] && (
                        <span
                            className={cn(
                                'mt-1 block text-label-micro',
                                STATUS_TONE[status] ?? 'text-text-3',
                            )}
                        >
                            {STATUS_LABEL[status]}
                            {day.compliance_score != null &&
                                ` · ${day.compliance_score}%`}
                        </span>
                    )}
                </span>
                <Icon
                    icon="mdi:chevron-down"
                    className="size-4 flex-none text-text-2 transition-transform group-aria-expanded:rotate-180"
                    aria-hidden
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="border-t border-border-strong px-4 py-3">
                {narration && (
                    <TemariTake analysis={narration} allowReanalyze={false} />
                )}
                {day.clamp && (
                    <ClampStepDown
                        clamp={day.clamp}
                        plannedKm={day.distance_km}
                    />
                )}
                <SessionBarGraph segments={day.segments} />
                {day.activities.map((run) => (
                    <Link
                        key={run.id}
                        href={`/activities/${run.id}`}
                        className="focus-ring mt-3 flex items-center gap-1.5 text-label-micro text-horizon-ink"
                    >
                        View activity · {runSummary(run)}
                        <Icon
                            icon="mdi:arrow-right"
                            className="size-3"
                            aria-hidden
                        />
                    </Link>
                ))}
                {(canMove || canSkip) && (
                    <div className="mt-3">
                        {picking ? (
                            <div className="grid grid-cols-7 gap-1.5">
                                {weekDays.map((target) => {
                                    const valid = isValidMoveTarget(target);
                                    return (
                                        <button
                                            key={target.date}
                                            type="button"
                                            disabled={!valid}
                                            onClick={() => {
                                                onMove(target.date);
                                                setPicking(false);
                                            }}
                                            className={cn(
                                                'focus-ring flex aspect-square flex-col items-center justify-center rounded-sm border border-border-strong text-xs font-semibold text-foreground',
                                                !valid && 'opacity-40',
                                            )}
                                        >
                                            {weekdayLabel(target.date)}
                                        </button>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="flex flex-wrap gap-3">
                                {canMove && (
                                    <button
                                        type="button"
                                        onClick={() => setPicking(true)}
                                        className="focus-ring flex items-center gap-1.5 text-label-micro text-horizon-ink"
                                    >
                                        <Icon
                                            icon="mdi:swap-horizontal"
                                            className="size-3"
                                            aria-hidden
                                        />
                                        Move this session
                                    </button>
                                )}
                                {canSkip && (
                                    <button
                                        type="button"
                                        onClick={onSkip}
                                        className="focus-ring flex items-center gap-1.5 text-label-micro text-text-2"
                                    >
                                        <Icon
                                            icon="mdi:skip-next"
                                            className="size-3"
                                            aria-hidden
                                        />
                                        Skip this session
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}
