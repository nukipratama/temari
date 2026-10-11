import type { Effort } from '@/types/inertia';

import Sheet from '@/components/ui/Sheet';
import { cn } from '@/lib/cn';
import { EFFORT_FILL, EFFORT_WORD } from '@/pages/Activities/calendarBars';

const EFFORTS: ReadonlyArray<Effort> = [
    'easy',
    'steady',
    'hard',
    'unknown',
    'rest',
];

export default function EffortLegend({
    open,
    onOpenChange,
}: Readonly<{ open: boolean; onOpenChange: (open: boolean) => void }>) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange} title="effort key">
            <ul className="mt-3 flex flex-col gap-3">
                {EFFORTS.map((effort) => (
                    <li
                        key={effort}
                        className="flex items-center gap-3 text-sm text-text-2"
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'block w-8 flex-none',
                                effort === 'rest'
                                    ? 'h-0 border-t-[3px] border-dashed border-border-strong'
                                    : cn(
                                          'h-1.5 rounded-full',
                                          EFFORT_FILL[effort],
                                      ),
                            )}
                        />
                        {EFFORT_WORD[effort]}
                    </li>
                ))}
            </ul>
        </Sheet>
    );
}
