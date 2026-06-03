import { Link } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { formatIdDate, formatKm } from '@/lib/pace';
import { aktivitasUrl } from '@/lib/routes';

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

const CARD_BASE = 'block rounded-2xl border border-line bg-surface-card p-4 sm:p-5';
const HEADING_CLASS = 'font-mono text-xs font-bold uppercase tracking-wider text-ink-2';

export default function PastYouStrip({ match, currentDistance, className }: Readonly<PastYouStripProps>) {
    const distanceLabel = currentDistance ? `${formatKm(currentDistance, 1)} km` : 'jarak ini';

    if (match === null) {
        return (
            <div className={cn(CARD_BASE, className)}>
                <h3 className={HEADING_CLASS}>Kamu vs Kamu Dulu</h3>
                <p className="mt-2 text-sm text-ink">Pertama kali di {distanceLabel}!</p>
            </div>
        );
    }

    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <h3 className={HEADING_CLASS}>Kamu vs Kamu Dulu</h3>
                {match.past.activity_id != null && (
                    <Icon icon="mdi:arrow-top-right" width={16} height={16} aria-hidden className="text-ink-3" />
                )}
            </div>
            <p className="mt-2 text-sm leading-relaxed text-ink">
                vs kamu <span className="font-semibold">{match.days_ago} hari lalu</span> di {distanceLabel}
            </p>
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <span className={cn('font-bold tabular-nums', diffTone(match.pace_diff_sec, 'pace'))}>
                    {Math.abs(Math.round(match.pace_diff_sec))} detik/km{' '}
                    {match.pace_diff_sec > 0 ? 'lebih cepat' : 'lebih lambat'}
                </span>
                {match.hr_diff_bpm !== null && (
                    <span className={cn('font-bold tabular-nums', diffTone(match.hr_diff_bpm, 'hr'))}>
                        {Math.abs(Math.round(match.hr_diff_bpm))} bpm {match.hr_diff_bpm < 0 ? 'lebih rendah' : 'lebih tinggi'}
                    </span>
                )}
            </div>
            <p className="mt-3 text-xs font-medium text-ink-2">
                {match.past.name ?? 'Aktivitas dulu'}
            </p>
            <p className="text-[11px] text-ink-3">
                {formatIdDate(match.past.start_date_local, 'long')}
            </p>
        </>
    );

    if (match.past.activity_id != null) {
        return (
            <Link
                href={aktivitasUrl({ activity_id: match.past.activity_id })}
                className={cn(CARD_BASE, 'focus:outline-none focus-visible:ring-2 focus-visible:ring-leaf', className)}
            >
                {body}
            </Link>
        );
    }

    return <div className={cn(CARD_BASE, className)}>{body}</div>;
}

// Pace: positive diff = faster (good); HR: negative diff = lower (good).
function diffTone(diff: number, kind: 'pace' | 'hr'): string {
    const good = kind === 'pace' ? diff > 0 : diff < 0;
    return good ? 'text-mood-enteng' : 'text-mood-lemes';
}
