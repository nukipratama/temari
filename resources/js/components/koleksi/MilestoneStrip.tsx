import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';

interface MilestoneStripProps {
    /** Target time the user is chasing, in seconds. */
    targetSec: number;
    /** How far they are from the target, in seconds. */
    deltaSec: number;
    /** Distance label e.g. "10K", "Half Marathon". */
    distanceLabel: string;
    className?: string;
}

export default function MilestoneStrip({
    targetSec,
    deltaSec,
    distanceLabel,
    className,
}: Readonly<MilestoneStripProps>) {
    const targetLabel = formatDurationHMS(targetSec);
    const deltaLabel = formatDurationHMS(Math.abs(deltaSec));

    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-5 rounded-2xl border border-horizon/40 bg-horizon/[0.12] px-6 py-4',
                className,
            )}
        >
            <div className="flex flex-wrap items-center gap-4">
                <div className="font-mono text-[9px] font-bold uppercase tracking-[0.14em] text-horizon">
                    ★ Target berikutnya
                </div>
                <div className="font-display text-[26px] leading-none tracking-[-0.015em] text-cream sm:text-[30px]">
                    Sub-<em className="italic">{targetLabel}</em> di {distanceLabel}
                </div>
            </div>
            <div className="font-mono text-[11px] uppercase tracking-[0.08em] text-cream/70">
                kurang{' '}
                <span className="font-bold text-horizon">{deltaLabel}</span>
            </div>
        </div>
    );
}
