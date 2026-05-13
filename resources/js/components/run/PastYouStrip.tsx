import { cn } from '@/lib/cn';
import { formatIdDate } from '@/lib/pace';

interface PastMatch {
    past: { start_date_local: string | null };
    pace_diff_sec: number;
    hr_diff_bpm: number | null;
    days_ago: number;
}

interface PastYouStripProps {
    match: PastMatch | null;
    currentDistance: number | null;
    className?: string;
}

export default function PastYouStrip({ match, currentDistance, className }: Readonly<PastYouStripProps>) {
    const distanceLabel = currentDistance ? `${(currentDistance / 1000).toFixed(1)} km` : 'jarak ini';

    return (
        <div
            className={cn(
                'rounded-2xl border border-line bg-surface-elev p-5 dark:border-line-dark dark:bg-surface-dark-elev',
                className,
            )}
        >
            <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-soft dark:text-ink-soft-dark">
                Kamu vs Kamu Dulu
            </h3>
            {match === null ? (
                <p className="mt-2 text-sm text-ink dark:text-ink-dark">Pertama kali di {distanceLabel}!</p>
            ) : (
                <>
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
                    <p className="mt-3 text-[11px] text-ink-soft dark:text-ink-soft-dark">
                        {formatIdDate(match.past.start_date_local, 'long')}
                    </p>
                </>
            )}
        </div>
    );
}

function paceTone(diff: number): string {
    return diff > 0 ? 'text-mood-bouncy' : 'text-mood-cooked';
}

function hrTone(diff: number): string {
    return diff < 0 ? 'text-mood-bouncy' : 'text-mood-cooked';
}
