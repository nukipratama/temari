import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    ArrowRightLeft,
    ChevronDown,
    Feather,
    SkipForward,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import type { PlanDay } from '@/lib/plan';
import type { AnalysisPayload, PlanDayClamp } from '@/types/inertia';

import {
    AskedRanResult,
    ChangeRow,
    DeltaTag,
} from '@/components/plan/DeltaPair';
import MiniSessionBar, { zoneColor } from '@/components/plan/MiniSessionBar';
import SessionBarGraph from '@/components/plan/SessionBarGraph';
import TemariTake from '@/components/plan/TemariTake';
import FlagWrong from '@/components/temari/FlagWrong';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';
import {
    clampSummary,
    deltaDirection,
    easedFromDelta,
    judgedDayResult,
    paceEaseDelta,
    paceLabel,
    SESSION_TYPE_ICON,
    SESSION_TYPE_LABEL,
    STATUS_LABEL,
    STATUS_MEANING,
    STATUS_TONE,
    volumeAdjustedFrom,
    weekdayLabel,
} from '@/lib/plan';
import { cardVariants } from '@/lib/variants';

/**
 * The whole day, for a rest day that was run anyway. Both halves are day
 * totals: mixing a summed distance with one run's clock is the bug this
 * replaced.
 */
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
                <Icon icon={ArrowDown} className="size-3" aria-hidden />
                {clamp.label}
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
 * actually run, and — on a day still ahead — the move and skip actions. A day
 * whose panel would carry none of that renders flat instead: no chevron, and
 * nothing focusable that would open onto an empty panel.
 */
export default function WeekDayRow({
    day,
    weekDays,
    today,
    narration,
    focused = false,
    onMove,
    onSkip,
}: Readonly<{
    day: PlanDay;
    weekDays: PlanDay[];
    today: string;
    narration: AnalysisPayload | null;
    /** The day the visitor arrived asking for, from `/plan?day=`. */
    focused?: boolean;
    onMove: (toDate: string) => void;
    onSkip: () => void;
}>) {
    const [picking, setPicking] = useState(false);
    const rowRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (focused) {
            rowRef.current?.scrollIntoView({ block: 'center' });
        }
    }, [focused]);

    const judged = judgedDayResult(day);
    const pace = judged === null ? paceLabel(day) : null;
    const isRest = day.session_type === 'rest';
    const ranAnyway = isRest && day.ran_anyway;
    const adjustedFrom = volumeAdjustedFrom(day);
    const weekFitDelta =
        adjustedFrom === null
            ? null
            : {
                  from: `${adjustedFrom}`,
                  to: `${day.distance_km}`,
                  direction: deltaDirection(adjustedFrom, day.distance_km),
              };
    const sessionDelta = day.eased_from
        ? easedFromDelta(day.eased_from, day)
        : null;
    const paceDelta = day.pace_eased_from
        ? paceEaseDelta(day.pace_eased_from, day)
        : null;
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

    // A rest day with nothing logged, no note, no clamp and no read has
    // nothing an expanded panel would show — a future day with a session
    // type but no segments yet (no VDOT to size them) is the same case. A
    // changed day is always expandable: the full old -> new pairs live only
    // in the panel now, never in the collapsed row.
    const hasSegments = day.segments.some((s) => (s.minutes ?? 0) > 0);
    const expandable =
        hasSegments ||
        sessionDelta !== null ||
        paceDelta !== null ||
        weekFitDelta !== null ||
        Boolean(day.eased_from?.voice) ||
        Boolean(day.pace_eased_from?.voice) ||
        narration !== null ||
        day.clamp !== null ||
        Boolean(day.credit_note) ||
        day.activities.length > 0 ||
        canMove ||
        canSkip;

    // Small mono tags on the collapsed row — never the old value or the
    // arrow, which live in the expanded panel's labelled rows instead.
    const tags = Array.from(
        new Set(
            [
                sessionDelta !== null || paceDelta !== null ? 'eased' : null,
                weekFitDelta !== null ? 'week fit' : null,
            ].filter((tag): tag is string => tag !== null),
        ),
    );

    const weekdayAndIcon = (
        <span className="flex w-9 flex-none flex-col items-center gap-1">
            <span className="text-label-micro text-text-2">
                {weekdayLabel(day.date)}
            </span>
            <Icon
                icon={SESSION_TYPE_ICON[day.session_type] ?? Feather}
                className="size-3.5"
                style={{ color: iconColor(day) }}
                aria-hidden
            />
        </span>
    );

    const summary = (
        <span className="min-w-0 flex-1">
            <span className="block text-sm font-semibold text-foreground">
                {SESSION_TYPE_LABEL[day.session_type] ?? day.session_type}
            </span>
            {!isRest && judged !== null && (
                <AskedRanResult
                    askedKm={judged.askedKm}
                    askedPace={judged.askedPace}
                    ranKm={judged.ranKm}
                    ranPace={judged.ranPace}
                />
            )}
            {!isRest && judged === null && (
                <span className="mt-0.5 block text-xs text-text-2">
                    {day.distance_km} km
                    {pace !== null && ` · ${pace}`}
                </span>
            )}
            {tags.length > 0 && (
                <span className="mt-0.5 flex flex-wrap gap-1.5">
                    {tags.map((tag) => (
                        <DeltaTag key={tag}>{tag}</DeltaTag>
                    ))}
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
                    title={STATUS_MEANING[status]}
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
    );

    const outerClass = cn(
        cardVariants({ padding: 'none' }),
        'overflow-hidden',
        day.date === today ? 'border-icon-accent' : 'border-border-strong',
    );

    if (!expandable) {
        return (
            <div ref={rowRef} className={outerClass}>
                <div className="flex items-center pr-1">
                    <div className="flex min-w-0 flex-1 items-center gap-3 py-3 pr-2 pl-4 text-left">
                        {weekdayAndIcon}
                        {summary}
                    </div>
                    <FlagWrong
                        subjectType="plan_day"
                        subjectId={day.id}
                        label="flag this day"
                        flagged={day.flagged === true}
                    />
                </div>
            </div>
        );
    }

    return (
        <Collapsible ref={rowRef} defaultOpen={focused} className={outerClass}>
            <div className="flex items-center pr-1">
                <CollapsibleTrigger className="group focus-ring flex min-w-0 flex-1 items-center gap-3 py-3 pr-2 pl-4 text-left">
                    {weekdayAndIcon}
                    {summary}
                    <Icon
                        icon={ChevronDown}
                        className="size-4 flex-none text-text-2 transition-transform group-aria-expanded:rotate-180"
                        aria-hidden
                    />
                </CollapsibleTrigger>
                <FlagWrong
                    subjectType="plan_day"
                    subjectId={day.id}
                    label="flag this day"
                    flagged={day.flagged === true}
                />
            </div>
            <CollapsibleContent className="border-t border-border-strong px-4 py-3">
                {sessionDelta?.typeFrom != null && (
                    <ChangeRow
                        label="type"
                        from={sessionDelta.typeFrom}
                        to={sessionDelta.typeTo}
                        direction="neutral"
                        tag="eased"
                    />
                )}
                {sessionDelta?.distanceFrom != null && (
                    <ChangeRow
                        className="mt-1.5"
                        label="km"
                        from={sessionDelta.distanceFrom}
                        to={sessionDelta.distanceTo}
                        direction={sessionDelta.direction}
                        tag="eased"
                    />
                )}
                {day.eased_from?.voice && (
                    <p className="mt-1 text-xs italic text-text-2">
                        {day.eased_from.voice}
                    </p>
                )}
                {paceDelta && (
                    <ChangeRow
                        className={sessionDelta ? 'mt-2' : undefined}
                        label="pace"
                        from={paceDelta.from}
                        to={paceDelta.to}
                        direction="neutral"
                        tag="eased"
                    />
                )}
                {day.pace_eased_from?.voice && (
                    <p className="mt-1 text-xs italic text-text-2">
                        {day.pace_eased_from.voice}
                    </p>
                )}
                {weekFitDelta && (
                    <ChangeRow
                        className={
                            sessionDelta || paceDelta ? 'mt-2' : undefined
                        }
                        label="km"
                        from={weekFitDelta.from}
                        to={weekFitDelta.to}
                        direction={weekFitDelta.direction}
                        tag="week fit"
                    />
                )}
                {narration && (
                    <TemariTake
                        analysis={narration}
                        label="Temari's read"
                        allowReanalyze={false}
                        className={
                            sessionDelta ||
                            paceDelta ||
                            weekFitDelta ||
                            day.eased_from?.voice ||
                            day.pace_eased_from?.voice
                                ? 'mt-2'
                                : undefined
                        }
                    />
                )}
                {day.clamp && (
                    <ClampStepDown
                        clamp={day.clamp}
                        plannedKm={day.distance_km}
                    />
                )}
                {day.credit_note && (
                    <p className="mt-2 text-xs italic text-text-2">
                        {day.credit_note}
                    </p>
                )}
                <SessionBarGraph segments={day.segments} />
                {day.activities.length > 0 && (
                    <div className="mt-3 flex min-w-0 flex-col gap-2">
                        {day.activities.map((run) => (
                            <Link
                                key={run.id}
                                href={`/activities/${run.id}`}
                                className="focus-ring flex items-center gap-1.5 text-label-micro text-horizon-ink"
                            >
                                View activity · {runSummary(run)}
                                <Icon
                                    icon={ArrowRight}
                                    className="size-3"
                                    aria-hidden
                                />
                            </Link>
                        ))}
                    </div>
                )}
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
                                            icon={ArrowRightLeft}
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
                                            icon={SkipForward}
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
