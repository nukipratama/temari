import type { RaceAmbition } from '@/types/inertia';

import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';
import { formatDurationHMS, formatPace } from '@/lib/pace';

interface SteppingStoneCardProps {
    ambition: RaceAmbition;
    className?: string;
}

/** An unsupported goal's stepping stone: the edge of on track, where its goal-pace work runs. */
export default function SteppingStoneCard({
    ambition,
    className,
}: Readonly<SteppingStoneCardProps>) {
    const timeSec = ambition.stepping_stone_time_sec;
    const paceSec = ambition.stepping_stone_pace_sec_per_km;
    if (timeSec === null || paceSec === null) {
        return null;
    }

    return (
        <section
            aria-label="stepping stone"
            className={cn(
                'rounded-lg border border-border bg-card p-4',
                className,
            )}
        >
            <Eyebrow as="h2" token="micro" tone="ink-2">
                stepping stone
            </Eyebrow>
            <p className="mt-1 flex flex-wrap items-baseline gap-x-2 font-mono tabular-nums">
                <span className="text-headline-xs font-extrabold text-foreground">
                    {formatDurationHMS(timeSec)}
                </span>
                <span className="text-xs text-text-2">
                    {formatPace(paceSec)}/km
                </span>
            </p>
            <p className="mt-2 text-xs leading-relaxed text-text-2">
                the edge of on track, 3% faster than your supported time. your
                stepping-stone sessions run here, and it moves as you get
                fitter.
            </p>
        </section>
    );
}
