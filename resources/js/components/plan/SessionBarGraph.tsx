import type { PlanSessionSegment } from '@/types/inertia';

import { HR_ZONE_COLORS, type HrZoneKey } from '@/lib/chartTokens';
import { formatPace } from '@/lib/pace';
import { GOAL_PACE_LABEL } from '@/lib/plan';

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

/** Distance leads, duration follows — the parts add up to the day's headline km. */
function figureText(segment: PlanSessionSegment): string {
    const parts = [
        segment.km == null ? null : `${segment.km} km`,
        segment.minutes == null ? null : `${segment.minutes} min`,
    ].filter(Boolean);

    return parts.length === 0 ? '—' : parts.join(' · ');
}

function paceSub(segment: PlanSessionSegment, label: string): string {
    return segment.pace_sec_per_km == null
        ? label
        : `${formatPace(segment.pace_sec_per_km)}/km · ${label}`;
}

/** Back-to-back segments of the same work key, with no recovery between them, as one rep or block. */
function legendGroups(segments: PlanSessionSegment[]): PlanSessionSegment[][] {
    const groups: PlanSessionSegment[][] = [];
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

function joined(group: PlanSessionSegment[]): PlanSessionSegment {
    if (group.length === 1) {
        return group[0];
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
        ...group[0],
        km: sum(group.map((segment) => segment.km)),
        minutes: sum(group.map((segment) => segment.minutes)),
    };
}

function closingNote(units: PlanSessionSegment[][]): string | undefined {
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
    sub,
    note,
    wide = false,
}: Readonly<{
    label: string;
    zone: string;
    figure: string;
    sub: string;
    note?: string;
    wide?: boolean;
}>) {
    return (
        <div className={wide ? 'min-w-0 flex-[2]' : 'min-w-0 flex-1'}>
            <div className="flex items-center gap-1 text-label-micro text-text-2">
                <span
                    className="size-1.5 flex-none rounded-full"
                    style={{ backgroundColor: zoneColor(zone) }}
                    aria-hidden
                />
                {label}
            </div>
            <div className="mt-0.5 text-xs font-semibold text-foreground">
                {figure}
            </div>
            <div className="text-xs text-text-2">{sub}</div>
            {note && <div className="text-xs text-text-2">{note}</div>}
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
    const recovery = segments.find((s) => s.key === 'recovery');
    const easy = segments.filter((s) => s.key === 'easy');
    const note = closesAtGoalPace
        ? closingNote(repeats.length > 0 ? repeats : mains)
        : undefined;

    return (
        <div className="mt-2.5">
            <div className="flex h-8 items-end gap-0.5">
                {Array.from({ length: segments.length }, (_, index) => (
                    <div
                        key={index}
                        className="rounded-t-xs"
                        style={{
                            width: `${((segments[index].minutes ?? 0) / total) * 100}%`,
                            height: `${ZONE_HEIGHT_PCT[segments[index].zone] ?? 50}%`,
                            backgroundColor: zoneColor(segments[index].zone),
                        }}
                    />
                ))}
            </div>
            <div className="mt-1.5 flex gap-2.5">
                {work ? (
                    <>
                        {warmup && (
                            <LegendItem
                                label={SEGMENT_LABEL.warmup}
                                zone={warmup.zone}
                                figure={figureText(warmup)}
                                sub={subFor(warmup)}
                            />
                        )}
                        <LegendItem
                            wide
                            label={`${repeats.length}× ${SEGMENT_LABEL[work.key]}`}
                            zone={work.zone}
                            figure={`${figureText(work)} hard / ${recovery?.minutes ?? 0} min easy`}
                            sub={subFor(work)}
                            note={note}
                        />
                        {easy.map((segment) => (
                            <LegendItem
                                key={`${segment.key}-${segment.zone}-${segment.minutes ?? 'unmeasured'}-${segment.km ?? 'open'}`}
                                label={SEGMENT_LABEL.easy}
                                zone={segment.zone}
                                figure={figureText(segment)}
                                sub={subFor(segment)}
                            />
                        ))}
                    </>
                ) : (
                    Array.from({ length: groups.length }, (_, index) => {
                        const unit = joined(groups[index]);
                        return (
                            <LegendItem
                                key={index}
                                label={SEGMENT_LABEL[unit.key]}
                                zone={unit.zone}
                                figure={figureText(unit)}
                                sub={subFor(unit)}
                                note={
                                    groups[index].length > 1 ? note : undefined
                                }
                            />
                        );
                    })
                )}
            </div>
        </div>
    );
}
