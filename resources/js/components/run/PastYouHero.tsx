import { Icon } from '@iconify/react';
import { Link } from '@inertiajs/react';

import Eyebrow from '@/components/ui/Eyebrow';
import GradientText from '@/components/ui/GradientText';
import { cn } from '@/lib/cn';
import { formatDuration } from '@/lib/pace';
import { activityUrl } from '@/lib/routes';

export interface PastYouMatch {
    past: {
        start_date_local: string | null;
        activity_id?: number | null;
        name?: string | null;
        distance?: number | null;
    };
    /** Positive = faster now. */
    pace_diff_sec: number;
    /** Positive = higher now. Null when either run has no HR. */
    hr_diff_bpm: number | null;
    /** Positive = this run finished sooner over the same distance. */
    time_diff_sec?: number;
    days_ago: number;
}

/** A delta where "up" is the bad direction, e.g. HR at the same effort. */
function lowerIsBetter(delta: number): string {
    if (delta === 0) {
        return 'text-cream';
    }

    return delta < 0 ? 'text-leaf' : 'text-citrus';
}

function higherIsBetter(delta: number): string {
    if (delta === 0) {
        return 'text-cream';
    }

    return delta > 0 ? 'text-leaf' : 'text-citrus';
}

interface PastYouHeroProps {
    match: PastYouMatch | null;
    className?: string;
}

/**
 * "You vs past you" — the claim the whole app is built on, so on the run page
 * it gets hero width and the page's one gradient number rather than a strip.
 */
export default function PastYouHero({
    match,
    className,
}: Readonly<PastYouHeroProps>) {
    if (match === null) {
        return null;
    }

    const paceDelta = Math.round(match.pace_diff_sec);
    const evenPace = paceDelta === 0;
    const pastKm =
        match.past.distance != null ? match.past.distance / 1000 : null;
    const timeDelta =
        match.time_diff_sec != null ? Math.round(match.time_diff_sec) : null;

    return (
        <div className={cn('flex flex-col gap-5', className)}>
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
                <div className="min-w-0">
                    <Eyebrow token="micro" tone="ink-on-sky">
                        You vs past you
                    </Eyebrow>
                    <p className="mt-2 flex flex-wrap items-baseline gap-x-2.5 font-serif leading-none">
                        {evenPace ? (
                            <span className="text-display-sm text-cream">
                                Dead even
                            </span>
                        ) : (
                            <>
                                <GradientText
                                    preset="cream-sun"
                                    fontSize="var(--text-display-md)"
                                    className="font-bold tabular-nums"
                                >
                                    {Math.abs(paceDelta)}
                                </GradientText>
                                <span className="text-headline-sm text-cream">
                                    sec/km {paceDelta > 0 ? 'faster' : 'slower'}
                                </span>
                            </>
                        )}
                    </p>
                    <p className="mt-2 font-sans text-sm leading-relaxed text-cream/70">
                        than{' '}
                        {pastKm != null
                            ? `the same ${pastKm.toFixed(1)} km`
                            : 'the same run'}
                        , {match.days_ago} days ago
                        {match.past.name != null && ` · ${match.past.name}`}
                    </p>
                </div>

                {match.past.activity_id != null && (
                    <Link
                        href={activityUrl({
                            activity_id: match.past.activity_id,
                        })}
                        className="focus-ring-on-sky inline-flex shrink-0 items-center gap-1.5 rounded-full border border-cream/20 px-3.5 py-2 text-label-micro text-cream/70 transition hover:border-cream/40 hover:text-cream"
                    >
                        View that run
                        <Icon
                            icon="mdi:arrow-right"
                            width={12}
                            height={12}
                            aria-hidden
                        />
                    </Link>
                )}
            </div>

            <dl className="flex flex-wrap gap-x-8 gap-y-3">
                {match.hr_diff_bpm !== null && (
                    <Delta
                        label="Heart rate"
                        value={`${Math.abs(Math.round(match.hr_diff_bpm))} bpm`}
                        suffix={
                            match.hr_diff_bpm === 0
                                ? 'the same'
                                : match.hr_diff_bpm < 0
                                  ? 'lower'
                                  : 'higher'
                        }
                        toneClass={lowerIsBetter(Math.round(match.hr_diff_bpm))}
                    />
                )}
                {timeDelta !== null && timeDelta !== 0 && (
                    <Delta
                        label="Over the distance"
                        value={formatDuration(Math.abs(timeDelta))}
                        suffix={timeDelta > 0 ? 'quicker' : 'slower'}
                        toneClass={higherIsBetter(timeDelta)}
                    />
                )}
            </dl>
        </div>
    );
}

function Delta({
    label,
    value,
    suffix,
    toneClass,
}: Readonly<{
    label: string;
    value: string;
    suffix: string;
    toneClass: string;
}>) {
    return (
        <div>
            <dt className="text-label-micro text-ink-on-sky">{label}</dt>
            <dd
                className={cn(
                    'mt-1 font-sans text-sm font-bold tabular-nums',
                    toneClass,
                )}
            >
                {value} {suffix}
            </dd>
        </div>
    );
}
