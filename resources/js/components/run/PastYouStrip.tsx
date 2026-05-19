import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { formatIdDate } from '@/lib/pace';

interface PastMatch {
    past: {
        start_date_local: string | null;
        activity_id?: number | null;
        name?: string | null;
    };
    pace_diff_sec: number;
    hr_diff_bpm: number | null;
    days_ago: number;
}

interface PastYouStripProps {
    match: PastMatch | null;
    currentDistance: number | null;
    className?: string;
}

const CARD_BASE = 'block rounded-2xl border border-line bg-surface-elev p-5 dark:border-line-dark dark:bg-surface-dark-elev';

export default function PastYouStrip({ match, currentDistance, className }: Readonly<PastYouStripProps>) {
    const distanceLabel = currentDistance ? `${(currentDistance / 1000).toFixed(1)} km` : 'jarak ini';

    if (match === null) {
        return (
            <div className={cn(CARD_BASE, className)}>
                <Heading />
                <p className="mt-2 text-sm text-ink dark:text-ink-dark">Pertama kali di {distanceLabel}!</p>
            </div>
        );
    }

    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <Heading />
                {match.past.activity_id != null && (
                    <Icon icon="mdi:arrow-top-right" width={16} height={16} aria-hidden className="text-ink-meta" />
                )}
            </div>
            <p className="mt-2 text-sm leading-relaxed text-ink dark:text-ink-dark">
                vs kamu <span className="font-semibold">{match.days_ago} hari lalu</span> di {distanceLabel}
            </p>
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <span className={cn('font-bold tabular-nums', paceTone(match.pace_diff_sec))}>
                    {Math.abs(Math.round(match.pace_diff_sec))} detik/km{' '}
                    {match.pace_diff_sec > 0 ? 'lebih cepat' : 'lebih lambat'}
                </span>
                {match.hr_diff_bpm !== null && (
                    <span className={cn('font-bold tabular-nums', hrTone(match.hr_diff_bpm))}>
                        {Math.abs(Math.round(match.hr_diff_bpm))} bpm {match.hr_diff_bpm < 0 ? 'lebih rendah' : 'lebih tinggi'}
                    </span>
                )}
            </div>
            <p className="mt-3 text-xs font-medium text-ink-soft dark:text-ink-soft-dark">
                {match.past.name ?? 'Aktivitas dulu'}
            </p>
            <p className="text-[11px] text-ink-meta dark:text-ink-meta-dark">
                {formatIdDate(match.past.start_date_local, 'long')}
            </p>
        </>
    );

    if (match.past.activity_id != null) {
        return (
            <Link
                href={`/aktivitas/${match.past.activity_id}`}
                className={cn(CARD_BASE, 'transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500', className)}
            >
                {body}
            </Link>
        );
    }

    return <div className={cn(CARD_BASE, className)}>{body}</div>;
}

function Heading(): ReactNode {
    return (
        <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
            Kamu vs Kamu Dulu
        </h3>
    );
}

function paceTone(diff: number): string {
    return diff > 0 ? 'text-mood-bouncy' : 'text-mood-cooked';
}

function hrTone(diff: number): string {
    return diff < 0 ? 'text-mood-bouncy' : 'text-mood-cooked';
}
