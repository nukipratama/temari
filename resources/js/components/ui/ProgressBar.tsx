import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/cn';

/**
 * Thin horizontal progress/fill bar. Replaces the three hand-rolled goal/splits
 * bars (HariIni GoalsCard, Target GoalCard, Runs/Show splits). `size` swaps the
 * track height + tint: `md` is the goal bar on a paper surface (cream-deep
 * track), `sm` the splits bar on a sky panel (glass track). `tone` colors the
 * fill.
 */
const progressTrackVariants = cva('overflow-hidden rounded-full', {
    variants: {
        size: {
            sm: 'h-1.5 bg-sky/[0.06]',
            md: 'h-2 bg-cream-deep',
        },
    },
    defaultVariants: {
        size: 'md',
    },
});

const progressFillVariants = cva('h-full rounded-full transition-all duration-500', {
    variants: {
        tone: {
            horizon: 'bg-horizon',
            sky: 'bg-sky',
        },
    },
    defaultVariants: {
        tone: 'horizon',
    },
});

type ProgressSize = NonNullable<VariantProps<typeof progressTrackVariants>['size']>;
type ProgressTone = NonNullable<VariantProps<typeof progressFillVariants>['tone']>;

interface ProgressBarProps {
    /** Fill ratio, `0`..`1`. Clamped into range. */
    value: number;
    tone?: ProgressTone;
    size?: ProgressSize;
    /** Accessible name for the bar (defaults to a generic label). */
    ariaLabel?: string;
    className?: string;
}

function clampRatio(value: number): number {
    if (Number.isNaN(value)) {
        return 0;
    }
    return Math.min(Math.max(value, 0), 1);
}

export default function ProgressBar({
    value,
    tone = 'horizon',
    size = 'md',
    ariaLabel,
    className,
}: Readonly<ProgressBarProps>) {
    const ratio = clampRatio(value);
    const pct = Math.round(ratio * 100);

    return (
        <div
            role="progressbar"
            aria-valuenow={pct}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={ariaLabel}
            className={cn(progressTrackVariants({ size }), className)}
        >
            <div className={progressFillVariants({ tone })} style={{ width: `${pct}%` }} />
        </div>
    );
}
