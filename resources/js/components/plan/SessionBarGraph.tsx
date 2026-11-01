import type { PlanSessionSegment } from '@/types/inertia';

import { HR_ZONE_COLORS, type HrZoneKey } from '@/lib/chartTokens';
import { formatPace, formatPaceWithRange } from '@/lib/pace';
import { approxDistance, GOAL_PACE_LABEL } from '@/lib/plan';

export function zoneColor(zone: string): string {
    return HR_ZONE_COLORS[zone as HrZoneKey] ?? 'var(--color-text-3)';
}

const SEGMENT_LABEL: Record<PlanSessionSegment['key'], string> = {
    warmup: 'warmup',
    main: 'main set',
    interval: 'interval',
    recovery: 'recovery',
    easy: 'easy running',
};

/** Bar height by zone — the ramp is the point, so it is not linear in zone number. */
const ZONE_HEIGHT_PCT: Record<string, number> = {
    Z1: 32,
    Z2: 52,
    Z3: 70,
    Z4: 86,
    Z5: 100,
};

/**
 * Each figure leads with the unit its step is run to: minutes for a warm-up,
 * a jog or hard work, km for easy running and a one-block day, the other
 * unit following as an approximation.
 */
function figureText(
    segment: PlanSessionSegment,
    byDistance: boolean,
    paceSecPerKm: number | null = segment.pace_sec_per_km,
): string {
    const minutes =
        segment.minutes == null ? null : `${Math.round(segment.minutes)} min`;
    const km = segment.km == null ? null : `${segment.km} km`;
    if (byDistance) {
        return km === null
            ? (minutes ?? '—')
            : minutes === null
              ? km
              : `${km} (~${minutes})`;
    }
    const distance =
        approxDistance(segment.minutes, paceSecPerKm) ??
        (km === null ? null : `~${km}`);

    return minutes === null
        ? (km ?? '—')
        : distance === null
          ? minutes
          : `${minutes} (${distance})`;
}

function runsToDistance(
    segment: PlanSessionSegment,
    segments: PlanSessionSegment[],
): boolean {
    return (
        segments.length === 1 ||
        (segment.pace_label === 'easy' &&
            segment.key !== 'warmup' &&
            segment.key !== 'recovery')
    );
}

function paceSub(segment: PlanSessionSegment, label: string): string {
    return segment.pace_sec_per_km == null
        ? label
        : `${formatPaceWithRange(segment.pace_sec_per_km, segment.pace_range)} · ${label}`;
}

type SegmentGroup = [PlanSessionSegment, ...PlanSessionSegment[]];

/** Back-to-back segments of the same work key, with no recovery between them, as one rep or block. */
function legendGroups(segments: PlanSessionSegment[]): SegmentGroup[] {
    const groups: SegmentGroup[] = [];
    for (const segment of segments) {
        const previous = groups.at(-1);
        if (
            previous !== undefined &&
            (segment.key === 'interval' || segment.key === 'main') &&
            previous[0].key === segment.key
        ) {
            previous.push(segment);
        } else {
            groups.push([segment]);
        }
    }

    return groups;
}

function joined(group: SegmentGroup): PlanSessionSegment {
    const [first] = group;
    if (group.length === 1) {
        return first;
    }
    const sum = (values: (number | null)[]) =>
        values.some((value) => value == null)
            ? null
            : Math.round(
                  values.reduce<number>(
                      (total, value) => total + (value ?? 0),
                      0,
                  ) * 10,
              ) / 10;

    return {
        ...first,
        km: sum(group.map((segment) => segment.km)),
        minutes: sum(group.map((segment) => segment.minutes)),
    };
}

/** The one pace a rep or block split at its closing quarter averages, which sizes its distance. */
function groupPace(group: SegmentGroup): number | null {
    let minutes = 0;
    let km = 0;
    for (const segment of group) {
        if (segment.minutes == null || segment.pace_sec_per_km == null) {
            return null;
        }
        minutes += segment.minutes;
        km += (segment.minutes * 60) / segment.pace_sec_per_km;
    }

    return km > 0 ? Math.round((minutes * 60) / km) : null;
}

function closingNote(units: SegmentGroup[]): string | undefined {
    const last = units.at(-1);
    if (last === undefined) {
        return undefined;
    }
    const closing = last.at(-1);
    if (closing?.pace_sec_per_km == null) {
        return undefined;
    }
    const at = `${formatPace(closing.pace_sec_per_km)}/km · ${GOAL_PACE_LABEL}`;
    if (last.length === 1) {
        return units.length > 1 ? `last at ${at}` : undefined;
    }
    if (units.length === 1) {
        return `last quarter at ${at}`;
    }

    return units.every((unit) => unit.length > 1)
        ? `last quarter of each at ${at}`
        : `last quarter of the last rep at ${at}`;
}

function LegendItem({
    label,
    zone,
    figure,
    between,
    sub,
    note,
    wide = false,
}: Readonly<{
    label: string;
    zone: string;
    figure: string;
    between?: string;
    sub: string;
    note?: string;
    wide?: boolean;
}>) {
    return (
        <div
            className={`flex min-w-0 gap-3 sm:block ${wide ? 'sm:flex-[2]' : 'sm:flex-1'}`}
        >
            <div className="flex w-28 flex-none items-center gap-1 self-start text-label-micro text-text-2 sm:w-auto">
                <span
                    className="size-1.5 flex-none rounded-full"
                    style={{ backgroundColor: zoneColor(zone) }}
                    aria-hidden
                />
                {label}
            </div>
            <div className="min-w-0">
                <div className="text-xs font-semibold text-foreground sm:mt-0.5">
                    {figure}
                </div>
                {between && (
                    <div className="text-xs text-text-2">{between}</div>
                )}
                <div className="text-xs text-text-2">{sub}</div>
                {note && <div className="text-xs text-text-2">{note}</div>}
            </div>
        </div>
    );
}

/**
 * The session laid out as its zone-coloured segments — width by minutes,
 * height by zone — with a legend beneath. An interval session collapses its
 * repeats into one "N× Interval" legend block rather than listing every rep,
 * which is how a coach would write the same session down.
 */
export default function SessionBarGraph({
    segments,
    workPaceLabel,
    closesAtGoalPace = false,
}: Readonly<{
    segments: PlanSessionSegment[];
    workPaceLabel?: string;
    closesAtGoalPace?: boolean;
}>) {
    const total = segments.reduce((sum, s) => sum + (s.minutes ?? 0), 0);
    const subFor = (segment: PlanSessionSegment) =>
        paceSub(
            segment,
            segment.pace_label !== 'easy' && workPaceLabel
                ? workPaceLabel
                : segment.pace_label,
        );

    if (segments.length === 0 || total === 0) {
        return null;
    }

    // Interval reps and a phase-split tempo day are the same shape on screen:
    // repeated work blocks around recoveries. Collapse whichever is present
    // rather than listing every block, which would be six legend items on a
    // Base tempo day.
    const groups = legendGroups(segments);
    const reps = groups.filter((g) => g[0].key === 'interval');
    const mains = groups.filter((g) => g[0].key === 'main');
    const repeats = reps.length > 0 ? reps : mains.length > 1 ? mains : [];
    const warmup = segments.find((s) => s.key === 'warmup');
    const work = repeats[0] === undefined ? undefined : joined(repeats[0]);
    const workPace = repeats[0] === undefined ? null : groupPace(repeats[0]);
    const recovery = segments.find((s) => s.key === 'recovery');
    const easy = segments.filter((s) => s.key === 'easy');
    const note = closesAtGoalPace
        ? closingNote(repeats.length > 0 ? repeats : mains)
        : undefined;

    return (
        <div className="mt-2.5">
            <div className="flex h-8 items-end gap-0.5">
                {Array.from(segments.entries(), ([index, segment]) => (
                    <div
                        key={index}
                        className="rounded-t-xs"
                        style={{
                            width: `${((segment.minutes ?? 0) / total) * 100}%`,
                            height: `${ZONE_HEIGHT_PCT[segment.zone] ?? 50}%`,
                            backgroundColor: zoneColor(segment.zone),
                        }}
                    />
                ))}
            </div>
            <div className="mt-1.5 flex flex-col gap-2 sm:flex-row sm:gap-2.5">
                {work ? (
                    <>
                        {warmup && (
                            <LegendItem
                                label={SEGMENT_LABEL.warmup}
                                zone={warmup.zone}
                                figure={figureText(warmup, false)}
                                sub={subFor(warmup)}
                            />
                        )}
                        <LegendItem
                            wide
                            label={`${repeats.length}× ${SEGMENT_LABEL[work.key]}`}
                            zone={work.zone}
                            figure={`${figureText(work, false, workPace)} hard`}
                            between={
                                recovery?.minutes == null
                                    ? undefined
                                    : `${Math.round(recovery.minutes)} min jog between`
                            }
                            sub={subFor(work)}
                            note={note}
                        />
                        {easy.map((segment) => (
                            <LegendItem
                                key={`${segment.key}-${segment.zone}-${segment.minutes ?? 'unmeasured'}-${segment.km ?? 'open'}`}
                                label={SEGMENT_LABEL.easy}
                                zone={segment.zone}
                                figure={figureText(segment, true)}
                                sub={subFor(segment)}
                            />
                        ))}
                    </>
                ) : (
                    Array.from(groups.entries(), ([index, group]) => {
                        const unit = joined(group);
                        return (
                            <LegendItem
                                key={index}
                                label={SEGMENT_LABEL[unit.key]}
                                zone={unit.zone}
                                figure={figureText(
                                    unit,
                                    runsToDistance(unit, segments),
                                    groupPace(group),
                                )}
                                sub={subFor(unit)}
                                note={group.length > 1 ? note : undefined}
                            />
                        );
                    })
                )}
            </div>
        </div>
    );
}
