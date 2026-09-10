import type { MonthTotals } from '@/pages/Activities/useCalendar';

import { CHIP_BASE } from '@/components/history/WeeklyStatusChips';
import { cn } from '@/lib/cn';

const CHIP_CLASS = cn(CHIP_BASE, 'bg-muted text-foreground');

/**
 * The month's own totals beside its recap narration: the same pill shape as
 * the weekly card's chips, at the coarser monthly grain.
 */
export default function MonthlyStatusChips({
    totals,
}: Readonly<{ totals: MonthTotals }>) {
    return (
        <>
            <span className={CHIP_CLASS}>Runs {totals.runs}</span>
            <span className={CHIP_CLASS}>
                Distance {totals.km.toFixed(1)} km
            </span>
            {totals.trimp !== null && (
                <span className={CHIP_CLASS}>
                    TRIMP {Math.round(totals.trimp)}
                </span>
            )}
        </>
    );
}
