import { cn } from '@/lib/cn';

export interface WeekVsLastWeekData {
    distance_delta_km: number;
    runs_delta: number;
    pace_delta_sec: number | null;
    this_week_km: number;
    this_week_runs: number;
}

interface WeekVsLastWeekProps {
    data: WeekVsLastWeekData | null;
    className?: string;
}

/**
 * Compact week-over-week comparison card on the Dashboard. Mirrors the
 * delta-coloring conventions of {@link ../run/PastYouStrip}: positive
 * pace delta (faster) = bouncy, negative (slower) = cooked. Hides when
 * the user doesn't have two weeks of data yet.
 */
export default function WeekVsLastWeek({ data, className }: Readonly<WeekVsLastWeekProps>) {
    if (data === null) return null;

    return (
        <section
            aria-label="Vs minggu lalu"
            className={cn(
                'rounded-2xl border border-line bg-surface-card p-4 shadow-sm sm:p-5',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <h3 className="font-mono text-xs font-bold uppercase tracking-wider text-ink-2">
                    Minggu ini vs minggu lalu
                </h3>
            </div>
            <p className="mt-2 text-sm leading-relaxed text-ink">
                Sejauh ini <span className="font-semibold">{data.this_week_km.toFixed(1)} km</span> dari{' '}
                <span className="font-semibold">{data.this_week_runs} lari</span>.
            </p>
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <Delta
                    value={data.distance_delta_km}
                    higherIsBetter
                    suffix=" km"
                    fmt={(n) => n.toFixed(1)}
                />
                <Delta
                    value={data.runs_delta}
                    higherIsBetter
                    suffix=" lari"
                    fmt={(n) => `${n}`}
                />
                {data.pace_delta_sec !== null && (
                    <Delta
                        value={data.pace_delta_sec}
                        higherIsBetter={false}
                        suffix=" detik/km"
                        fmt={(n) => `${Math.round(n)}`}
                        labelOverride={(n) => (n < 0 ? 'lebih cepat' : 'lebih lambat')}
                    />
                )}
            </div>
        </section>
    );
}

interface DeltaProps {
    value: number;
    higherIsBetter: boolean;
    suffix: string;
    fmt: (n: number) => string;
    labelOverride?: (n: number) => string;
}

function Delta({ value, higherIsBetter, suffix, fmt, labelOverride }: Readonly<DeltaProps>) {
    let sign: string;
    if (value > 0) {
        sign = '+';
    } else if (value < 0) {
        sign = '−';
    } else {
        sign = '±';
    }
    const good = higherIsBetter ? value > 0 : value < 0;
    const tone = value === 0 ? 'text-ink-3' : good ? 'text-mood-enteng' : 'text-mood-lemes';
    let label: string;
    if (labelOverride) {
        label = labelOverride(value);
    } else if (value > 0) {
        label = 'lebih banyak';
    } else if (value < 0) {
        label = 'lebih sedikit';
    } else {
        label = 'sama';
    }

    return (
        <span className={cn('font-bold tabular-nums', tone)}>
            {sign}
            {fmt(Math.abs(value))}
            {suffix} {label}
        </span>
    );
}
