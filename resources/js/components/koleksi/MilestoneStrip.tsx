import Eyebrow from '@/components/ui/Eyebrow';
import ProgressBar from '@/components/ui/ProgressBar';
import { useCountUp } from '@/hooks/useCountUp';
import { cn } from '@/lib/cn';
import { formatDurationHMS } from '@/lib/pace';

interface MilestoneStripProps {
    /** Target time the user is chasing, in seconds. */
    targetSec: number;
    /** How far they are from the target, in seconds. */
    deltaSec: number;
    /** The runner's current best time for this distance, in seconds. */
    currentSec: number;
    /** Distance label e.g. "10K", "Half Marathon". */
    distanceLabel: string;
    className?: string;
}

export default function MilestoneStrip({
    targetSec,
    deltaSec,
    currentSec,
    distanceLabel,
    className,
}: Readonly<MilestoneStripProps>) {
    const targetLabel = formatDurationHMS(targetSec);
    const deltaLabel = formatDurationHMS(Math.abs(deltaSec));
    const ratio = currentSec > 0 ? Math.min(targetSec / currentSec, 1) : 0;
    const pctDisplay = Math.round(useCountUp(ratio * 100));

    return (
        <div
            className={cn(
                'flex flex-col gap-3 rounded-lg border border-horizon/40 bg-horizon/[0.12] px-6 py-4',
                className,
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-5">
                <div className="flex flex-wrap items-center gap-4">
                    <Eyebrow token="micro" tone="horizon">
                        ★ Next target
                    </Eyebrow>
                    <div className="font-display text-headline-sm text-cream">
                        Sub-<em className="italic">{targetLabel}</em>{' '}
                        {distanceLabel}
                    </div>
                </div>
                <div className="font-mono text-[11px] uppercase tracking-[0.08em] text-cream/70">
                    <span className="font-bold text-horizon">{deltaLabel}</span>{' '}
                    to go
                </div>
            </div>
            <div className="flex items-center gap-3">
                <ProgressBar
                    value={ratio}
                    tone="horizon"
                    size="sm"
                    ariaLabel={`${pctDisplay}% of the way to sub-${targetLabel} ${distanceLabel}`}
                    className="flex-1"
                />
                <span className="font-mono text-[11px] font-bold tabular-nums text-horizon">
                    {pctDisplay}%
                </span>
            </div>
        </div>
    );
}
