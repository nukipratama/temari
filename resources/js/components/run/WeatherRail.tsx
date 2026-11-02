import { cn } from '@/lib/cn';

/** A peak this close to the mean is the same reading, so the rail leaves it off. */
export const PEAK_MIN_RISE_C = 2;

/** Two labels on one side of the rail closer than this share of its width would overlap. */
const LABEL_MIN_GAP_PCT = 22;

type Reading = 'avg' | 'feels' | 'dew' | 'peak';

interface Marker {
    reading: Reading;
    value: number;
    label: string;
    pct: number;
    above: boolean;
}

const MARKER_CLASS: Record<Reading, string> = {
    avg: 'size-3 rounded-full border-2 border-muted bg-foreground',
    feels: 'size-2.5 rotate-45 border-2 border-foreground bg-muted',
    dew: 'size-2 rounded-full border-2 border-text-3 bg-muted',
    peak: 'h-3.5 w-0.5 bg-foreground',
};

const LABEL_CLASS: Record<Reading, string> = {
    avg: 'font-bold text-foreground',
    feels: 'text-text-2',
    dew: 'text-text-3',
    peak: 'text-text-3',
};

const SPOKEN: Record<Reading, string> = {
    avg: 'average',
    feels: 'felt like',
    dew: 'dew point',
    peak: 'peak',
};

/**
 * Lays the readings out on one °C scale fitted to this run: dew point and peak
 * label above the rail, average and feels-like below, and a label that would
 * crowd its neighbour moves to the other side when that side has room.
 */
export function railMarkers(
    readings: Readonly<{
        avg: number;
        feels: number | null;
        dew: number | null;
        peak: number | null;
    }>,
): { markers: Marker[]; low: number; high: number } {
    const shown: Omit<Marker, 'pct'>[] = [
        {
            reading: 'avg',
            value: readings.avg,
            label: `avg ${readings.avg}°`,
            above: false,
        },
    ];
    if (readings.feels != null) {
        shown.push({
            reading: 'feels',
            value: readings.feels,
            label: `feels ${readings.feels}°`,
            above: false,
        });
    }
    if (readings.dew != null) {
        shown.push({
            reading: 'dew',
            value: readings.dew,
            label: `dew ${readings.dew}°`,
            above: true,
        });
    }
    if (
        readings.peak != null &&
        readings.peak - readings.avg >= PEAK_MIN_RISE_C
    ) {
        shown.push({
            reading: 'peak',
            value: readings.peak,
            label: `peak ${readings.peak}°`,
            above: true,
        });
    }

    const values = shown.map((m) => m.value);
    const low = Math.floor((Math.min(...values) - 2) / 5) * 5;
    const high = Math.ceil((Math.max(...values) + 2) / 5) * 5;
    const markers = shown.map((m) => ({
        ...m,
        pct: ((m.value - low) / (high - low)) * 100,
    }));

    for (const above of [true, false]) {
        const side = markers
            .filter((m) => m.above === above)
            .sort((a, b) => a.pct - b.pct);
        for (let i = 1; i < side.length; i++) {
            const prev = side[i - 1] as Marker;
            const next = side[i] as Marker;
            if (next.pct - prev.pct >= LABEL_MIN_GAP_PCT) {
                continue;
            }
            const mover = next.reading === 'avg' ? prev : next;
            const otherSideCrowded = markers.some(
                (m) =>
                    m.above !== above &&
                    Math.abs(m.pct - mover.pct) < LABEL_MIN_GAP_PCT,
            );
            if (!otherSideCrowded) {
                mover.above = !above;
            }
        }
    }

    return { markers, low, high };
}

/**
 * The run's air on one thermometer: average, feels-like, dew point and, when
 * the run warmed up, its peak, with the gap between average and feels-like
 * shaded. Needs a second reading beside the average to say anything, so it
 * renders nothing on runs that only stored a temperature.
 */
export default function WeatherRail({
    avg,
    feels,
    dew,
    peak,
    className,
}: Readonly<{
    avg: number;
    feels: number | null;
    dew: number | null;
    peak: number | null;
    className?: string;
}>) {
    const { markers, low, high } = railMarkers({ avg, feels, dew, peak });
    if (markers.length < 2) {
        return null;
    }

    const at = (value: number) => ((value - low) / (high - low)) * 100;
    const spoken = markers
        .map((m) => `${SPOKEN[m.reading]} ${m.value}°C`)
        .join(', ');

    return (
        <div
            role="img"
            aria-label={spoken}
            className={cn('px-5 py-6', className)}
            data-weather-rail
        >
            <div className="relative h-1.5 rounded-full bg-border-strong">
                {feels != null && (
                    <div
                        aria-hidden
                        data-felt-band
                        className="absolute inset-y-0 rounded-full bg-icon-accent"
                        style={{
                            left: `${at(Math.min(avg, feels))}%`,
                            width: `${at(Math.max(avg, feels)) - at(Math.min(avg, feels))}%`,
                        }}
                    />
                )}
                <span
                    aria-hidden
                    className="absolute top-1/2 right-full -translate-y-1/2 pr-1.5 font-mono text-label-micro text-text-3"
                >
                    {low}
                </span>
                <span
                    aria-hidden
                    className="absolute top-1/2 left-full -translate-y-1/2 pl-1.5 font-mono text-label-micro text-text-3"
                >
                    {high}
                </span>
                {markers.map((m) => (
                    <div key={m.reading} aria-hidden>
                        <span
                            data-marker={m.reading}
                            className={cn(
                                'absolute top-1/2 -translate-x-1/2 -translate-y-1/2',
                                MARKER_CLASS[m.reading],
                            )}
                            style={{ left: `${m.pct}%` }}
                        />
                        <span
                            data-label={m.reading}
                            data-side={m.above ? 'above' : 'below'}
                            className={cn(
                                'absolute -translate-x-1/2 whitespace-nowrap font-mono text-label-micro tabular-nums',
                                m.above ? 'bottom-full mb-2' : 'top-full mt-2',
                                LABEL_CLASS[m.reading],
                            )}
                            style={{ left: `${m.pct}%` }}
                        >
                            {m.label}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
