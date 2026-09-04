import { Link } from '@inertiajs/react';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
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
        return 'text-text-2';
    }

    return delta < 0 ? 'text-leaf-ink' : 'text-citrus-ink';
}

function higherIsBetter(delta: number): string {
    if (delta === 0) {
        return 'text-text-2';
    }

    return delta > 0 ? 'text-leaf-ink' : 'text-citrus-ink';
}

/**
 * "You vs past you" — the claim the whole app is built on, drawn as the
 * prototype does: its own card directly under the hero, leading with the pace
 * delta and linking to the run it is measured against. No match means no card,
 * not an empty state.
 */
export default function PastYouCard({
    match,
    className,
}: Readonly<{ match: PastYouMatch | null; className?: string }>) {
    if (match === null) {
        return null;
    }

    const paceDelta = Math.round(match.pace_diff_sec);
    const evenPace = paceDelta === 0;
    const pastKm =
        match.past.distance != null ? match.past.distance / 1000 : null;
    const timeDelta =
        match.time_diff_sec != null ? Math.round(match.time_diff_sec) : null;
    const hrDelta =
        match.hr_diff_bpm != null ? Math.round(match.hr_diff_bpm) : null;

    return (
        <Card as="section" padding="hero" className={className}>
            <Eyebrow token="micro" tone="ink-2">
                You vs past you
            </Eyebrow>
            <p className="mt-1.5 flex flex-wrap items-baseline gap-x-2 font-mono font-bold leading-tight tabular-nums text-icon-accent">
                {evenPace ? (
                    <span className="text-stat-sm text-foreground">
                        Dead even
                    </span>
                ) : (
                    <>
                        <span className="text-stat-sm">
                            {Math.abs(paceDelta)}
                        </span>
                        <span className="font-sans text-quote-sm font-semibold text-text-2">
                            sec/km {paceDelta > 0 ? 'faster' : 'slower'}
                        </span>
                    </>
                )}
            </p>
            <p className="mt-1 font-sans text-xs leading-relaxed text-text-2">
                than{' '}
                {pastKm != null
                    ? `the same ${pastKm.toFixed(1)} km`
                    : 'the same run'}
                , {match.days_ago} days ago
                {match.past.name != null && ` · ${match.past.name}`}
            </p>

            {match.past.activity_id != null && (
                <Link
                    href={activityUrl({ activity_id: match.past.activity_id })}
                    className="focus-ring mt-2 inline-flex items-center gap-1 rounded font-sans text-xs font-bold text-icon-accent"
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

            <dl className="mt-4 grid grid-cols-2 gap-3">
                {hrDelta !== null && (
                    <Delta
                        label="Heart rate"
                        value={`${Math.abs(hrDelta)} bpm`}
                        suffix={
                            hrDelta === 0
                                ? 'the same'
                                : hrDelta < 0
                                  ? 'lower'
                                  : 'higher'
                        }
                        toneClass={lowerIsBetter(hrDelta)}
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
        </Card>
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
            <dt className="text-label-micro text-text-3">{label}</dt>
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
