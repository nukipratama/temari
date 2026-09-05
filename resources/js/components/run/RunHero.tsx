import { motion } from 'framer-motion';

import type { ActivityDetail, Mood } from '@/types/inertia';

import MapWeatherPanel from '@/components/run/MapWeatherPanel';
import FaceIcon from '@/components/temari/FaceIcon';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import MoodChip from '@/components/ui/MoodChip';
import { useCountUp } from '@/hooks/useCountUp';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatPace, formatShortDateTimeId } from '@/lib/pace';

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
            icon: 'mdi:heart-pulse',
            value: display(hr, hrCount, rounded),
            unit: 'bpm',
        },
        {
            label: 'TRIMP',
            icon: 'mdi:fire',
            value: display(trimp, trimpCount, rounded),
            unit: null,
        },
        {
            label: 'ELEV',
            icon: 'mdi:trending-up',
            value: display(
                detail.total_elevation_gain ?? null,
                elevationCount,
                rounded,
            ),
            unit: 'm',
        },
    ];

    return (
        <section className="rounded-panel border border-border-strong bg-card p-5 shadow-e1">
            <header className="flex items-start gap-3.5">
                <FaceIcon size={56} />
                <div className="min-w-0 flex-1">
                    <Eyebrow token="micro" tone="ink-2">
                        {formatShortDateTimeId(detail.start_date_local)}
                    </Eyebrow>
                    <h1 className="mt-1 font-serif text-quote-lg italic text-foreground">
                        {detail.name ?? 'run'}
                    </h1>
                    <MoodChip mood={mood} className="mt-1.5" />
                </div>
                {onShare && (
                    <button
                        type="button"
                        onClick={onShare}
                        className="focus-ring pressable -mr-1 -mt-1 inline-flex flex-none items-center gap-1.5 rounded-full border border-border-strong px-3 py-1.5 text-label-micro text-text-2 transition hover:text-foreground"
                    >
                        <Icon
                            icon="mdi:share-variant"
                            width={12}
                            height={12}
                            aria-hidden
                        />
                        Share
                    </button>
                )}
            </header>

            <motion.div
                variants={staggerContainer}
                initial="hidden"
                animate="visible"
            >
                <div className="mt-4 flex items-end justify-between gap-3">
                    <motion.div variants={fadeInUp}>
                        <div className="flex items-baseline gap-1">
                            <b className="font-mono text-stat font-bold tabular-nums tracking-[-0.02em] text-foreground">
                                {display(detail.distance, distanceKm, (n) =>
                                    n.toFixed(2),
                                )}
                            </b>
                            <span className="text-label-micro text-text-2">
                                km
                            </span>
                        </div>
                        <Eyebrow token="micro" tone="ink-3" className="mt-1">
                            DISTANCE
                        </Eyebrow>
                    </motion.div>
                    <div className="flex flex-col items-end gap-1.5 pb-0.5">
                        <SupportingStat
                            icon="mdi:timer-outline"
                            label="DURATION"
                            value={duration}
                        />
                        <SupportingStat
                            icon="mdi:lightning-bolt"
                            label="PACE"
                            value={`${display(paceSec, paceCount, formatPace)}/km`}
                        />
                    </div>
                </div>

                <div className="mt-3.5 grid grid-cols-3 gap-1.5 min-[360px]:gap-2">
                    {secondary.map((stat) => (
                        <motion.div
                            key={stat.label}
                            variants={fadeInUp}
                            className="flex items-center gap-1.5 rounded-sm bg-muted px-2 py-2 min-[360px]:gap-2 min-[360px]:px-2.5"
                        >
                            <Icon
                                icon={stat.icon}
                                width={14}
                                height={14}
                                aria-hidden
                                className="flex-none text-icon-accent"
                            />
                            <div className="min-w-0">
                                <div className="leading-none">
                                    <b className="font-mono text-sm font-bold tabular-nums text-foreground">
                                        {stat.value}
                                    </b>
                                    {stat.unit && (
                                        <span className="ml-0.5 text-label-micro text-text-2">
                                            {stat.unit}
                                        </span>
                                    )}
                                </div>
                                <span className="mt-1 block truncate text-label-micro text-text-3">
                                    {stat.label}
                                </span>
                            </div>
                        </motion.div>
                    ))}
                </div>
            </motion.div>

            <MapWeatherPanel detail={detail} className="mt-4" />
        </section>
    );
}

function SupportingStat({
    icon,
    label,
    value,
}: Readonly<{ icon: string; label: string; value: string }>) {
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
