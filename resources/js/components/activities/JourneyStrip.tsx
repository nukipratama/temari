import Eyebrow from '@/components/ui/Eyebrow';
import Card from '@/components/ui/LegacyCard';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { formatShortDateTimeId, formatPace } from '@/lib/pace';

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
export default function JourneyStrip({
    match,
    className,
}: Readonly<JourneyStripProps>) {
    // Hooks must run before the early return below: match can flip from null to a value.
    const countedTotalKm = useCountUp(match?.total_km ?? 0);

    if (match === null) return null;

    const { first, current, pace_improvement_sec, hr_improvement_bpm } = match;

    return (
        <Card as="section" padding="hero" className={className}>
            <Eyebrow as="h3" token="hero" tone="ink-2">
                You vs Your First Run
            </Eyebrow>
            <p className="mt-2 font-sans text-sm leading-relaxed text-foreground">
                Total{' '}
                <span className="font-semibold text-horizon-ink">
                    {countedTotalKm.toFixed(1)} km
                </span>{' '}
                logged since your first run
                {first.date && (
                    <>
                        {' '}
                        on{' '}
                        <span className="font-semibold">
                            {formatShortDateTimeId(first.date)}
                        </span>
                    </>
                )}
                .
            </p>
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1.5 font-serif text-quote-md italic">
                {pace_improvement_sec !== null && (
                    <span
                        className={cn(
                            'tabular-nums',
                            pace_improvement_sec === 0
                                ? 'text-text-2'
                                : pace_improvement_sec > 0
                                  ? 'text-leaf-ink'
                                  : 'text-ember-ink',
                        )}
                    >
                        {pace_improvement_sec === 0 ? (
                            'Same pace as your first run'
                        ) : (
                            <>
                                {Math.abs(Math.round(pace_improvement_sec))}{' '}
                                sec/km{' '}
                                {pace_improvement_sec > 0 ? 'faster' : 'slower'}
                            </>
                        )}
                    </span>
                )}
                {hr_improvement_bpm !== null && (
                    <span
                        className={cn(
                            'tabular-nums',
                            hr_improvement_bpm === 0
                                ? 'text-text-2'
                                : hr_improvement_bpm > 0
                                  ? 'text-leaf-ink'
                                  : 'text-ember-ink',
                        )}
                    >
                        {hr_improvement_bpm === 0 ? (
                            'Same HR as your first run'
                        ) : (
                            <>
                                {Math.abs(Math.round(hr_improvement_bpm))} bpm{' '}
                                {hr_improvement_bpm > 0 ? 'lower' : 'higher'}
                            </>
                        )}
                    </span>
                )}
            </div>
            <PaceLine label="Your first run" summary={first} />
            <PaceLine
                label="Most recent run"
                summary={current}
                className="mt-1"
            />
        </Card>
    );
}

function PaceLine({
    label,
    summary,
    className,
}: Readonly<{ label: string; summary: ActivitySummary; className?: string }>) {
    const paceLabel =
        summary.pace_sec_per_km !== null
            ? formatPace(summary.pace_sec_per_km)
            : null;
    return (
        <p
            className={cn(
                'mt-3 text-xs leading-relaxed text-text-2',
                className,
            )}
        >
            <span className="font-semibold text-foreground">{label}:</span>{' '}
            {summary.name ?? 'Run'}{' '}
            {summary.distance_km !== null && (
                <>· {summary.distance_km.toFixed(2)} km </>
            )}
            {paceLabel && <>· pace {paceLabel}/km</>}
        </p>
    );
}
