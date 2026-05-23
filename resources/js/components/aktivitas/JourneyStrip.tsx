import { formatPace } from '@/lib/pace';
import { cn } from '@/lib/cn';

interface ActivitySummary {
    date: string | null;
    name: string | null;
    distance_km: number | null;
    pace_sec_per_km: number | null;
    avg_hr: number | null;
}

export interface JourneyMatchData {
    first: ActivitySummary;
    current: ActivitySummary;
    pace_improvement_sec: number | null;
    hr_improvement_bpm: number | null;
    total_km: number;
}

interface JourneyStripProps {
    match: JourneyMatchData | null;
    className?: string;
}

/**
 * All-time progress strip — first ever run vs most recent. Surfaces the
 * "I'm a different runner now" moment. Pace + HR improvements (when both
 * sides have HRM data) are signed: positive = faster / lower HR = good.
 *
 * Hides when the user only has one activity since the comparison is
 * meaningless.
 */
export default function JourneyStrip({ match, className }: Readonly<JourneyStripProps>) {
    if (match === null) return null;

    const { first, current, pace_improvement_sec, hr_improvement_bpm, total_km } = match;

    return (
        <section
            aria-label="Perjalanan lari"
            className={cn(
                'rounded-2xl border border-line bg-surface-elev p-4 shadow-sm sm:p-5',
                className,
            )}
        >
            <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-3">
                Kamu vs Lari Pertama Kamu
            </h3>
            <p className="mt-2 text-sm leading-relaxed text-ink">
                Total <span className="font-semibold">{total_km.toFixed(1)} km</span> kekumpul sejak lari pertama
                {first.date && (
                    <>
                        {' '}
                        di <span className="font-semibold">{formatDate(first.date)}</span>
                    </>
                )}
                .
            </p>
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                {pace_improvement_sec !== null && (
                    <span
                        className={cn(
                            'font-bold tabular-nums',
                            pace_improvement_sec > 0 ? 'text-mood-enteng' : 'text-mood-lemes',
                        )}
                    >
                        {Math.abs(Math.round(pace_improvement_sec))} detik/km{' '}
                        {pace_improvement_sec > 0 ? 'lebih cepat' : 'lebih lambat'}
                    </span>
                )}
                {hr_improvement_bpm !== null && (
                    <span
                        className={cn(
                            'font-bold tabular-nums',
                            hr_improvement_bpm > 0 ? 'text-mood-enteng' : 'text-mood-lemes',
                        )}
                    >
                        {Math.abs(Math.round(hr_improvement_bpm))} bpm{' '}
                        {hr_improvement_bpm > 0 ? 'lebih rendah' : 'lebih tinggi'}
                    </span>
                )}
            </div>
            <PaceLine label="Lari pertama kamu" summary={first} />
            <PaceLine label="Lari terbaru" summary={current} className="mt-1" />
        </section>
    );
}

function PaceLine({ label, summary, className }: Readonly<{ label: string; summary: ActivitySummary; className?: string }>) {
    const paceLabel = summary.pace_sec_per_km !== null ? formatPace(summary.pace_sec_per_km) : null;
    return (
        <p className={cn('mt-3 text-xs text-ink-3', className)}>
            <span className="font-semibold text-ink-2">{label}:</span>{' '}
            {summary.name ?? 'Lari'}{' '}
            {summary.distance_km !== null && <>· {summary.distance_km.toFixed(2)} km </>}
            {paceLabel && <>· pace {paceLabel}/km</>}
        </p>
    );
}

function formatDate(iso: string): string {
    try {
        return new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
    } catch {
        return iso;
    }
}
