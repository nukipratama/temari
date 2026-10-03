import type { ReactNode } from 'react';

import {
    Flame,
    HeartPulse,
    Share2,
    Timer,
    TrendingUp,
    Zap,
} from 'lucide-react';

import type { ActivityDetail, Mood } from '@/types/inertia';

import MapWeatherPanel from '@/components/run/MapWeatherPanel';
import PrBibStamp, { type PrBib } from '@/components/run/PrBibStamp';
import MascotWatermark from '@/components/temari/MascotWatermark';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon, IconComponent } from '@/components/ui/Icon';
import MoodChip from '@/components/ui/MoodChip';
import StatTile from '@/components/ui/StatTile';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { EFFORT_STRIPE_CLASS } from '@/lib/effort';
import { formatPace, formatShortDateTimeId } from '@/lib/pace';
import { revealDelay } from '@/lib/styles';

interface RunHeroProps {
    detail: ActivityDetail;
    mood: Mood;
    /** Elapsed time, pre-formatted H:MM:SS. */
    duration: string;
    paceSec: number | null;
    hr: number | null;
    trimp: number | null;
    /** Opens the share-card popup. Omitted when this run has no card to share. */
    onShare?: () => void;
    /** Non-null only on the one view that plays the record's bib stamp. */
    prBib?: PrBib | null;
    /** Sits beside the mood chip, e.g. the saved effort score. */
    effort?: ReactNode;
}

function display(
    raw: number | null,
    tweened: number,
    format: (n: number) => string,
): string {
    return raw != null ? format(tweened) : '—';
}

/**
 * The run's headline panel: who/when/what, then the distance as the one big
 * number with duration and pace beside it, three supporting readings, and the
 * route + conditions slab. Mirrors the prototype's `HeroPanel` stat hierarchy —
 * one headline stat, not a six-tile grid of equals.
 */
export default function RunHero({
    detail,
    mood,
    duration,
    paceSec,
    hr,
    trimp,
    onShare,
    prBib = null,
    effort = null,
}: Readonly<RunHeroProps>) {
    const distanceKm = useCountUp(
        detail.distance != null ? detail.distance / 1000 : 0,
    );
    const paceCount = useCountUp(paceSec ?? 0);
    const hrCount = useCountUp(hr ?? 0);
    const trimpCount = useCountUp(trimp ?? 0);
    const elevationCount = useCountUp(detail.total_elevation_gain ?? 0);

    const rounded = (n: number) => `${Math.round(n)}`;
    const secondary = [
        {
            label: 'HR',
            icon: HeartPulse,
            value: display(hr, hrCount, rounded),
            unit: 'bpm',
        },
        {
            label: 'TRIMP',
            icon: Flame,
            value: display(trimp, trimpCount, rounded),
            unit: null,
        },
        {
            label: 'ELEV',
            icon: TrendingUp,
            value: display(
                detail.total_elevation_gain ?? null,
                elevationCount,
                rounded,
            ),
            unit: 'm',
        },
    ];

    return (
        <section
            className={cn(
                'relative isolate overflow-hidden pl-3',
                EFFORT_STRIPE_CLASS[detail.effort ?? 'unknown'],
            )}
        >
            <MascotWatermark pose={mood} className="-top-3 -right-14" />
            <header
                data-run-morph={detail.activity_id}
                className="flex items-start gap-3.5"
            >
                <div className="min-w-0 flex-1">
                    <Eyebrow token="micro" tone="ink-2">
                        {formatShortDateTimeId(detail.start_date_local)}
                    </Eyebrow>
                    <h1 className="mt-1 font-serif text-quote-lg italic text-foreground">
                        {detail.name ?? 'run'}
                    </h1>
                    <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                        <MoodChip mood={mood} />
                        {effort}
                    </div>
                    {prBib !== null && (
                        <PrBibStamp bib={prBib} className="mt-1.5" />
                    )}
                </div>
                {onShare && (
                    <button
                        type="button"
                        onClick={onShare}
                        className="focus-ring pressable inline-flex flex-none items-center gap-1.5 rounded-full border border-border-strong px-3 py-1.5 text-label-micro text-text-2 transition hover:text-foreground"
                    >
                        <Icon
                            icon={Share2}
                            width={12}
                            height={12}
                            aria-hidden
                        />
                        Share
                    </button>
                )}
            </header>

            <div>
                <div className="mt-4 flex items-end justify-between gap-3">
                    <div className="reveal" style={revealDelay(0)}>
                        <div className="flex items-baseline gap-1">
                            <b className="text-stat">
                                {display(detail.distance, distanceKm, (n) =>
                                    n.toFixed(2),
                                )}
                            </b>
                            <span className="text-meta">km</span>
                        </div>
                        <Eyebrow token="micro" tone="ink-3" className="mt-1">
                            DISTANCE
                        </Eyebrow>
                    </div>
                    <div className="flex flex-col items-end gap-1.5 pb-0.5">
                        <SupportingStat
                            icon={Timer}
                            label="DURATION"
                            value={duration}
                        />
                        <SupportingStat
                            icon={Zap}
                            label="PACE"
                            value={`${display(paceSec, paceCount, formatPace)}/km`}
                        />
                    </div>
                </div>

                <div className="mt-3.5 grid grid-cols-2 gap-2 min-[360px]:grid-cols-3">
                    {secondary.map((stat, index) => (
                        <StatTile
                            key={stat.label}
                            style={revealDelay(index + 1)}
                            className="reveal"
                            icon={stat.icon}
                            label={stat.label}
                            value={stat.value}
                            delta={
                                stat.unit && (
                                    <span className="text-label-micro text-text-2">
                                        {stat.unit}
                                    </span>
                                )
                            }
                        />
                    ))}
                </div>
            </div>

            <MapWeatherPanel detail={detail} className="mt-4" />
        </section>
    );
}

function SupportingStat({
    icon,
    label,
    value,
}: Readonly<{ icon: IconComponent; label: string; value: string }>) {
    return (
        <div className="flex items-center gap-1.5">
            <span className="sr-only">{label}</span>
            <span className="font-mono text-sm font-bold tabular-nums text-foreground">
                {value}
            </span>
            <Icon
                icon={icon}
                width={12}
                height={12}
                aria-hidden
                className="flex-none text-icon-accent"
            />
        </div>
    );
}
