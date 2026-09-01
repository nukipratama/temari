import Eyebrow from '@/components/ui/Eyebrow';
import LegacyCard from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { formatPace } from '@/lib/pace';

export interface TrainingPaces {
    easy: number;
    marathon: number;
    threshold: number;
    interval: number;
}

/** Slowest first, so the rail reads easy → hard left to right. */
const MARKERS = [
    { key: 'easy', label: 'Easy', below: false },
    { key: 'marathon', label: 'Marathon', below: true },
    { key: 'threshold', label: 'Tempo', below: false },
    { key: 'interval', label: 'Interval', below: true },
] as const;

/**
 * The four training paces on one rail. The prototype hardcodes each marker's
 * left offset; here the offsets are the paces themselves, linearly placed
 * between the slowest and the fastest, so a runner whose tempo sits unusually
 * close to their marathon pace sees those two markers crowd together.
 */
export default function PaceTargetsCard({
    paces,
}: Readonly<{ paces: TrainingPaces }>) {
    const values = MARKERS.map((m) => paces[m.key]);
    const slowest = Math.max(...values);
    const fastest = Math.min(...values);
    const span = slowest - fastest;

    return (
        <LegacyCard as="section">
            <Eyebrow token="micro" tone="ink-3">
                Training · pace targets · per km
            </Eyebrow>
            <div className="relative mx-2 mt-2.5 h-[78px]">
                <div className="absolute inset-x-0 top-[39px] h-1 rounded-full bg-gradient-to-r from-leaf to-horizon" />
                {MARKERS.map((marker) => {
                    const pace = paces[marker.key];
                    const left =
                        span === 0 ? 50 : ((slowest - pace) / span) * 100;
                    const value = (
                        <span className="text-center leading-tight whitespace-nowrap">
                            <b className="block font-mono text-xs font-bold tabular-nums text-foreground">
                                {formatPace(pace)}
                            </b>
                            <span className="block text-label-micro text-text-2">
                                {marker.label}
                            </span>
                        </span>
                    );

                    return (
                        <div
                            key={marker.key}
                            className={cn(
                                'absolute flex -translate-x-1/2 flex-col items-center gap-1.5',
                                marker.below ? 'bottom-0' : 'top-0',
                            )}
                            style={{ left: `${left}%` }}
                        >
                            {!marker.below && value}
                            <i className="size-2 flex-none rounded-full bg-foreground ring-[3px] ring-card" />
                            {marker.below && value}
                        </div>
                    );
                })}
            </div>
        </LegacyCard>
    );
}
