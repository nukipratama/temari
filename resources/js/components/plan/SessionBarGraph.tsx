import type { PlanSessionSegment } from '@/types/inertia';

import { zoneColor } from '@/components/plan/MiniSessionBar';
import { formatPace } from '@/lib/pace';

const SEGMENT_LABEL: Record<PlanSessionSegment['key'], string> = {
    warmup: 'Warmup',
    main: 'Main set',
    interval: 'Interval',
    recovery: 'Recovery',
    cooldown: 'Cooldown',
};

/** Bar height by zone — the ramp is the point, so it is not linear in zone number. */
const ZONE_HEIGHT_PCT: Record<string, number> = {
    Z1: 32,
    Z2: 52,
    Z3: 70,
    Z4: 86,
    Z5: 100,
};

function minutesText(minutes: number | null): string {
    return minutes == null ? '—' : `${minutes} min`;
}

function paceSub(segment: PlanSessionSegment): string {
    return segment.pace_sec_per_km == null
        ? segment.pace_label
        : `${formatPace(segment.pace_sec_per_km)}/km · ${segment.pace_label}`;
}

function LegendItem({
    label,
    zone,
    figure,
    sub,
    wide = false,
}: Readonly<{
    label: string;
    zone: string;
    figure: string;
    sub: string;
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
}: Readonly<{ segments: PlanSessionSegment[] }>) {
    const total = segments.reduce((sum, s) => sum + (s.minutes ?? 0), 0);

    if (segments.length === 0 || total === 0) {
        return null;
    }

    const reps = segments.filter((s) => s.key === 'interval');
    const warmup = segments.find((s) => s.key === 'warmup');
    const cooldown = segments.find((s) => s.key === 'cooldown');
    const work = reps[0];
    const recovery = segments.find((s) => s.key === 'recovery');

    return (
        <div className="mt-2.5">
            <div className="flex h-8 items-end gap-0.5">
                {segments.map((segment, index) => (
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
            <div className="mt-1.5 flex gap-2.5">
                {work ? (
                    <>
                        {warmup && (
                            <LegendItem
                                label={SEGMENT_LABEL.warmup}
                                zone={warmup.zone}
                                figure={minutesText(warmup.minutes)}
                                sub={paceSub(warmup)}
                            />
                        )}
                        <LegendItem
                            wide
                            label={`${reps.length}× ${SEGMENT_LABEL.interval}`}
                            zone={work.zone}
                            figure={`${work.minutes ?? '—'} min hard / ${recovery?.minutes ?? 0} min easy`}
                            sub={paceSub(work)}
                        />
                        {cooldown && (
                            <LegendItem
                                label={SEGMENT_LABEL.cooldown}
                                zone={cooldown.zone}
                                figure={minutesText(cooldown.minutes)}
                                sub={paceSub(cooldown)}
                            />
                        )}
                    </>
                ) : (
                    segments.map((segment, index) => (
                        <LegendItem
                            key={index}
                            label={SEGMENT_LABEL[segment.key]}
                            zone={segment.zone}
                            figure={minutesText(segment.minutes)}
                            sub={paceSub(segment)}
                        />
                    ))
                )}
            </div>
        </div>
    );
}
