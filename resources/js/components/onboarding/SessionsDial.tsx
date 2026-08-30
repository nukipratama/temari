import { motion } from 'framer-motion';

import { cn } from '@/lib/cn';
import { pressShrink } from '@/lib/motion';

/**
 * A row of ascending bars for picking a weekly session count — each bar's
 * height grows with its value, taller bars fill first, so the choice reads
 * as a dial rather than a plain button row.
 */
export default function SessionsDial({
    options,
    value,
    onChange,
}: Readonly<{
    options: readonly number[];
    value: number | null;
    onChange: (n: number) => void;
}>) {
    return (
        <div className="flex items-end gap-2.5">
            {options.map((n, index) => {
                const filled = value !== null && n <= value;
                return (
                    <div key={n} className="flex flex-col items-center gap-1.5">
                        <motion.button
                            type="button"
                            onClick={() => onChange(n)}
                            whileTap={pressShrink}
                            aria-pressed={n === value}
                            aria-label={`${n}x`}
                            style={{ height: `${26 + index * 9}px` }}
                            className={cn(
                                'focus-ring w-7 rounded-t-md border-2',
                                filled
                                    ? 'border-icon-accent bg-icon-accent'
                                    : 'border-border-strong bg-transparent',
                            )}
                        />
                        <span
                            className={cn(
                                'font-mono text-xs font-bold',
                                n === value
                                    ? 'text-icon-accent'
                                    : 'text-foreground',
                            )}
                        >
                            {n}x
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
