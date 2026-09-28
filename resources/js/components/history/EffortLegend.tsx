import type { Effort } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { EFFORT_FILL, EFFORT_WORD } from '@/pages/Activities/calendarBars';

const EFFORTS: ReadonlyArray<Effort> = [
    'easy',
    'steady',
    'hard',
    'unknown',
    'rest',
];

/** The calendar's effort key, replacing the old mood legend: a swatch plus a word per effort. */
export default function EffortLegend({
    className,
}: Readonly<{ className?: string }>) {
    return (
        <div className={className}>
            <span className="text-label-micro text-text-3">effort</span>
            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                {EFFORTS.map((effort) => (
                    <span
                        key={effort}
                        className="flex items-center gap-1.5 text-xs text-text-2"
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'block h-1.5 w-4 rounded-full',
                                effort === 'rest'
                                    ? 'border-t-2 border-dashed border-border-strong'
                                    : EFFORT_FILL[effort],
                            )}
                        />
                        {EFFORT_WORD[effort]}
                    </span>
                ))}
            </div>
        </div>
    );
}
