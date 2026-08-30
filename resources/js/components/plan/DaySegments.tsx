import type { PlanSessionSegment } from '@/types/inertia';

import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Icon } from '@/components/ui/Icon';
import { HR_ZONE_COLORS, type HrZoneKey } from '@/lib/chartTokens';
import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';

const SEGMENT_LABEL: Record<PlanSessionSegment['key'], string> = {
    warmup: 'Warmup',
    main: 'Main set',
    interval: 'Interval',
    recovery: 'Recovery',
    cooldown: 'Cooldown',
};

function zoneColor(zone: string): string {
    return HR_ZONE_COLORS[zone as HrZoneKey] ?? 'var(--color-text-3)';
}

function totalMinutes(segments: PlanSessionSegment[]): number {
    return segments.reduce((sum, s) => sum + (s.minutes ?? 0), 0);
}

/** A thin, always-visible zone-colored strip: the shape of the session at a glance. */
function ZoneStrip({
    segments,
    total,
}: Readonly<{ segments: PlanSessionSegment[]; total: number }>) {
    if (total === 0) {
        return null;
    }

    return (
        <div className="mt-1.5 flex h-1 gap-px" aria-hidden>
            {Array.from({ length: segments.length }, (_, index) => (
                <div
                    key={index}
                    className="rounded-full"
                    style={{
                        width: `${((segments[index].minutes ?? 0) / total) * 100}%`,
                        backgroundColor: zoneColor(segments[index].zone),
                    }}
                />
            ))}
        </div>
    );
}

/**
 * The day's warmup/main/cooldown breakdown: a zone-colored strip always
 * visible, expanding to per-segment minutes and pace on request. Renders
 * nothing on a rest day, whose segments are always empty.
 */
export default function DaySegments({
    segments,
}: Readonly<{ segments: PlanSessionSegment[] }>) {
    const total = totalMinutes(segments);

    if (segments.length === 0) {
        return null;
    }

    return (
        <Collapsible>
            <ZoneStrip segments={segments} total={total} />
            <CollapsibleTrigger className="group focus-ring mt-1 flex items-center gap-1 text-xs text-text-3 hover:text-foreground">
                Segments
                <Icon
                    icon="mdi:chevron-down"
                    width={12}
                    height={12}
                    className="transition-transform group-aria-expanded:rotate-180"
                />
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="mt-2 flex h-6 items-end gap-0.5">
                    {Array.from({ length: segments.length }, (_, index) => (
                        <div
                            key={index}
                            className="rounded-t-sm"
                            style={{
                                width: `${((segments[index].minutes ?? 0) / total) * 100}%`,
                                height: '100%',
                                backgroundColor: zoneColor(
                                    segments[index].zone,
                                ),
                            }}
                        />
                    ))}
                </div>
                <ul className="mt-2 flex flex-col gap-1">
                    {Array.from({ length: segments.length }, (_, index) => {
                        const seg = segments[index];
                        return (
                            <li
                                key={index}
                                className={cn(
                                    'flex items-center gap-2 text-xs text-text-2',
                                )}
                            >
                                <span
                                    className="h-1.5 w-1.5 shrink-0 rounded-full"
                                    style={{
                                        backgroundColor: zoneColor(seg.zone),
                                    }}
                                    aria-hidden
                                />
                                <span className="font-semibold text-foreground">
                                    {SEGMENT_LABEL[seg.key]}
                                </span>
                                {seg.minutes != null && (
                                    <span>{seg.minutes} min</span>
                                )}
                                {seg.pace_sec_per_km != null && (
                                    <span>
                                        {formatPace(seg.pace_sec_per_km)}/km
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}
