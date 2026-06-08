import Card from '@/components/ui/Card';
import { formatDayMonthYearId, formatPace } from '@/lib/pace';
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
        <Card as="section" padding="lg" className={className}>
            <h3 className="font-mono text-[11px] font-bold uppercase tracking-[0.16em] text-ink-2">
                Kamu vs Lari Pertama Kamu
            </h3>
            <p className="mt-2 font-sans text-sm leading-relaxed text-ink">
                Total <span className="font-semibold text-horizon-deep">{total_km.toFixed(1)} km</span> kekumpul sejak lari pertama
                {first.date && (
                    <>
                        {' '}
                        di <span className="font-semibold">{formatDate(first.date)}</span>
                    </>
                )}
                .
            </p>
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1.5 font-display text-base italic">
                {pace_improvement_sec !== null && (
                    <span
                        className={cn(
                            'tabular-nums',
                            pace_improvement_sec > 0 ? 'text-leaf-deep' : 'text-ember-deep',
                        )}
                    >
                        {Math.abs(Math.round(pace_improvement_sec))} detik/km{' '}
                        {pace_improvement_sec > 0 ? 'lebih cepat' : 'lebih lambat'}
                    </span>
                )}
                {hr_improvement_bpm !== null && (
                    <span
                        className={cn(
                            'tabular-nums',
                            hr_improvement_bpm > 0 ? 'text-leaf-deep' : 'text-ember-deep',
                        )}
                    >
                        {Math.abs(Math.round(hr_improvement_bpm))} bpm{' '}
                        {hr_improvement_bpm > 0 ? 'lebih rendah' : 'lebih tinggi'}
                    </span>
                )}
            </div>
            <PaceLine label="Lari pertama kamu" summary={first} />
            <PaceLine label="Lari terbaru" summary={current} className="mt-1" />
        </Card>
    );
}

function PaceLine({ label, summary, className }: Readonly<{ label: string; summary: ActivitySummary; className?: string }>) {
    const paceLabel = summary.pace_sec_per_km !== null ? formatPace(summary.pace_sec_per_km) : null;
    return (
        <p className={cn('mt-3 text-[12px] leading-relaxed text-ink-2', className)}>
            <span className="font-semibold text-ink">{label}:</span>{' '}
            {summary.name ?? 'Lari'}{' '}
            {summary.distance_km !== null && <>· {summary.distance_km.toFixed(2)} km </>}
            {paceLabel && <>· pace {paceLabel}/km</>}
        </p>
    );
}

function formatDate(iso: string): string {
    try {
        return formatDayMonthYearId(new Date(iso));
    } catch {
        return iso;
    }
}
