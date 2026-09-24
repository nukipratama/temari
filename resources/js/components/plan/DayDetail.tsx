import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowRight,
    ArrowRightLeft,
    SkipForward,
} from 'lucide-react';
import { useState } from 'react';

import type { PlanDay } from '@/lib/plan';
import type { AnalysisPayload, PlanDayClamp } from '@/types/inertia';

import {
    AskedRanResult,
    ChangeRow,
    DeltaTag,
} from '@/components/plan/DeltaPair';
import SessionBarGraph from '@/components/plan/SessionBarGraph';
import TemariTake from '@/components/plan/TemariTake';
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
    prescriptionWhy,
    ranHot,
    SESSION_TYPE_LABEL,
    sessionPurpose,
    STATUS_LABEL,
    STATUS_MEANING,
    STATUS_TONE,
    volumeAdjustedFrom,
    weekdayLabel,
} from '@/lib/plan';

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

const RUNS_SHOWN = 2;

function RunList({ runs }: Readonly<{ runs: PlanDay['activities'] }>) {
    const [expanded, setExpanded] = useState(false);
    const shown = expanded ? runs : runs.slice(0, RUNS_SHOWN);
    const hidden = runs.length - shown.length;

    return (
        <div className="mt-3">
            <p className="text-label-micro text-text-2">
                {runs.length === 1 ? 'Run' : 'Runs'}
            </p>
            <ul className="mt-1 divide-y divide-border">
                {shown.map((run) => (
                    <li key={run.id}>
                        <Link
                            href={`/activities/${run.id}`}
                            aria-label={`view activity · ${runSummary(run)}`}
                            className="focus-ring flex items-center gap-3 py-2 text-sm text-foreground tabular-nums hover:text-horizon-ink"
                        >
                            <span className="flex-1">{run.km} km</span>
                            {run.seconds != null && (
                                <span>{formatDurationHMS(run.seconds)}</span>
                            )}
                            <Icon
                                icon={ArrowRight}
                                className="size-3.5 text-horizon-ink"
                                aria-hidden
                            />
                        </Link>
                    </li>
                ))}
            </ul>
            {hidden > 0 && (
                <button
                    type="button"
                    onClick={() => setExpanded(true)}
                    className="focus-ring mt-1 text-sm font-semibold text-horizon-ink"
                >
                    + {hidden} more
                </button>
            )}
        </div>
    );
}

function complianceLabel(day: PlanDay): string {
    if (
        day.compliance_score != null &&
        day.compliance_score > 100 &&
        day.credited_km != null &&
        day.prescribed_km != null
    ) {
        if (day.actual_km !== null && day.actual_km !== day.credited_km) {
            return `${day.actual_km.toFixed(1)} km logged · ${day.credited_km.toFixed(1)} of ${day.prescribed_km.toFixed(1)} km counted · ${day.compliance_score}% distance`;
        }

        return `${day.credited_km.toFixed(1)} of ${day.prescribed_km.toFixed(1)} km · ${day.compliance_score}% distance`;
    }

    return `${day.compliance_score}%`;
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

function dayChanges(day: PlanDay) {
    const adjustedFrom = volumeAdjustedFrom(day);
    const trimmed = adjustedFrom !== null && adjustedFrom > day.distance_km;

    return {
        sessionDelta: day.eased_from
            ? easedFromDelta(day.eased_from, day)
            : null,
        paceDelta: day.pace_eased_from
            ? paceEaseDelta(day.pace_eased_from, day)
            : null,
        weekFitDelta:
            adjustedFrom === null
                ? null
                : {
                      from: `${adjustedFrom}`,
                      to: `${day.distance_km}`,
                      direction: deltaDirection(adjustedFrom, day.distance_km),
                      tag: trimmed ? 'trimmed' : 'topped up',
                      why: trimmed ? 'ahead on the week' : 'making up the week',
                  },
    };
}

function isValidMoveTarget(day: PlanDay, target: PlanDay, today: string) {
    return (
        target.date !== day.date &&
        target.date > today &&
        target.session_type === 'rest'
    );
}

function dayActions(day: PlanDay, weekDays: PlanDay[], today: string) {
    const editable = day.date > today && day.session_type !== 'rest';

    return {
        canMove:
            editable &&
            weekDays.some((target) => isValidMoveTarget(day, target, today)),
        canSkip: editable && !day.skipped,
    };
}

function dayPoint(day: PlanDay) {
    return {
        purpose: ['long', 'tempo', 'interval'].includes(day.session_type)
            ? sessionPurpose(day)
            : null,
        doseWhy: prescriptionWhy(day),
    };
}

/** Whether a day's narration has anything to show: a read still pending, or done with nothing to say, draws no block. */
export function showsNarration(
    narration: AnalysisPayload | null,
): narration is AnalysisPayload {
    return (
        narration !== null &&
        narration.status !== 'pending' &&
        !(narration.status === 'done' && narration.content === null)
    );
}

/**
 * Whether {@see DayDetail} would draw anything for this day. A rest day with
 * nothing logged, no note, no clamp and no read has nothing to show; a future
 * day with a session type but no segments yet (no VDOT to size them) is the
 * same case. A changed day always has detail: the full old -> new pairs live
 * only there, never in the headline.
 */
export function hasDayDetail(
    day: PlanDay,
    weekDays: PlanDay[],
    today: string,
    narration: AnalysisPayload | null,
): boolean {
    const { sessionDelta, paceDelta, weekFitDelta } = dayChanges(day);
    const { purpose, doseWhy } = dayPoint(day);
    const { canMove, canSkip } = dayActions(day, weekDays, today);

    return (
        day.segments.some((s) => (s.minutes ?? 0) > 0) ||
        sessionDelta !== null ||
        paceDelta !== null ||
        weekFitDelta !== null ||
        Boolean(day.eased_from?.voice) ||
        Boolean(day.pace_eased_from?.voice) ||
        showsNarration(narration) ||
        purpose !== null ||
        doseWhy !== null ||
        day.clamp !== null ||
        Boolean(day.credit_note) ||
        Boolean(day.hot_note) ||
        day.activities.length > 0 ||
        canMove ||
        canSkip
    );
}

/**
 * A day in one glance: its session, what it asked (and, once judged, what
 * was run), small change tags, and the verdict. Tags never carry the old
 * value or an arrow; those live in {@see DayDetail}'s labelled rows.
 */
export function DayHeadline({ day }: Readonly<{ day: PlanDay }>) {
    const judged = judgedDayResult(day);
    const pace = judged === null ? paceLabel(day) : null;
    const isRest = day.session_type === 'rest';
    const { sessionDelta, paceDelta, weekFitDelta } = dayChanges(day);
    // A day excused before it passes is still `planned` server-side until
    // plan:score-compliance runs the next morning; the headline says "skipped" now.
    let status: string = day.skipped ? 'skip' : day.status;
    if (status === 'overreached' && ranHot(day)) {
        status = 'hot';
    }

    const tags = Array.from(
        new Set(
            [
                sessionDelta !== null || paceDelta !== null ? 'eased' : null,
                weekFitDelta?.tag ?? null,
            ].filter((tag): tag is string => tag !== null),
        ),
    );

    return (
        <span className="block min-w-0 flex-1">
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
            {isRest && day.ran_anyway && (
                <span className="mt-0.5 block text-xs font-semibold text-leaf-ink">
                    Ran anyway · {daySummary(day)}
                </span>
            )}
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
                        ` · ${complianceLabel(day)}`}
                </span>
            )}
        </span>
    );
}

/**
 * Everything a day holds beyond its headline: the eased-value change rows,
 * what the session is for, Temari's read on it, the readiness step-down
 * beside the standing prescription, credit and heat notes, the segment
 * graph, what was actually run and, on a day still ahead, Move and Skip.
 */
export default function DayDetail({
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

    const { sessionDelta, paceDelta, weekFitDelta } = dayChanges(day);
    const { purpose, doseWhy } = dayPoint(day);
    const { canMove, canSkip } = dayActions(day, weekDays, today);
    const showsPoint = purpose !== null || doseWhy !== null;

    return (
        <>
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
                    className={sessionDelta || paceDelta ? 'mt-2' : undefined}
                    label="km"
                    from={weekFitDelta.from}
                    to={weekFitDelta.to}
                    direction={weekFitDelta.direction}
                    tag={weekFitDelta.why}
                    quiet
                />
            )}
            {showsPoint && (
                <div
                    className={cn(
                        sessionDelta || paceDelta || weekFitDelta
                            ? 'mt-2'
                            : undefined,
                    )}
                >
                    <p className="text-label-micro text-text-3">the point</p>
                    {purpose && (
                        <p className="mt-1 text-xs leading-relaxed text-foreground">
                            {purpose}
                        </p>
                    )}
                    {doseWhy && (
                        <p className="mt-1 text-xs italic text-text-2">
                            {doseWhy}
                        </p>
                    )}
                </div>
            )}
            {showsNarration(narration) && (
                <TemariTake
                    analysis={narration}
                    label="Temari's read"
                    allowReanalyze={false}
                    className={
                        sessionDelta ||
                        paceDelta ||
                        weekFitDelta ||
                        showsPoint ||
                        day.eased_from?.voice ||
                        day.pace_eased_from?.voice
                            ? 'mt-2'
                            : undefined
                    }
                />
            )}
            {day.clamp && (
                <ClampStepDown clamp={day.clamp} plannedKm={day.distance_km} />
            )}
            {day.credit_note && (
                <p className="mt-2 text-xs italic text-text-2">
                    {day.credit_note}
                </p>
            )}
            {day.hot_note && (
                <p className="mt-2 text-xs italic text-text-2">
                    {day.hot_note}
                </p>
            )}
            <SessionBarGraph segments={day.segments} />
            {day.activities.length > 0 && <RunList runs={day.activities} />}
            {(canMove || canSkip) && (
                <div className="mt-3">
                    {picking ? (
                        <div className="grid grid-cols-7 gap-1.5">
                            {weekDays.map((target) => {
                                const valid = isValidMoveTarget(
                                    day,
                                    target,
                                    today,
                                );
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
        </>
    );
}
