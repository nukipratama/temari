import { motion } from 'framer-motion';

import { cn } from '@/lib/cn';
import { pressShrink } from '@/lib/motion';

export type TrendRange = '30d' | '90d' | '12mo';

const TREND_RANGES: ReadonlyArray<{
    key: TrendRange;
    label: string;
}> = [
    { key: '30d', label: '30 days' },
    { key: '90d', label: '90 days' },
    { key: '12mo', label: '12 months' },
];

interface RangeToggleProps {
    value: TrendRange;
    onChange: (range: TrendRange) => void;
    className?: string;
}

/**
 * A year of trend data is fetched once (see TrendsController) and sliced
 * client-side per range, so switching here never round-trips to the server —
 * this only ever updates local state.
 */
export default function RangeToggle({
    value,
    onChange,
    className,
}: Readonly<RangeToggleProps>) {
    return (
        <div
            role="group"
            aria-label="Time range"
            className={cn('flex gap-1 rounded-full bg-muted p-1', className)}
        >
            {TREND_RANGES.map((range) => {
                const selected = range.key === value;
                return (
                    <motion.button
                        key={range.key}
                        type="button"
                        whileTap={pressShrink}
                        aria-pressed={selected}
                        onClick={() => onChange(range.key)}
                        className={cn(
                            'flex-1 rounded-full py-2 text-center text-xs font-bold whitespace-nowrap transition-colors',
                            selected
                                ? 'bg-card text-foreground shadow-e1'
                                : 'text-foreground hover:text-text-2',
                        )}
                    >
                        {range.label}
                    </motion.button>
                );
            })}
        </div>
    );
}
