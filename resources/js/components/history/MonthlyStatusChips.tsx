import type { MonthTotals } from '@/pages/Activities/useCalendar';

const CHIP_BASE =
    'inline-flex items-center gap-1 rounded-full bg-muted px-1.75 py-0.5 font-mono text-[0.5rem] leading-[1.2] font-extrabold tracking-[.03em] text-foreground uppercase';

/**
 * The month's own totals beside its recap narration: the same pill shape as
 * the weekly card's chips, at the coarser monthly grain.
 */
export default function MonthlyStatusChips({
    totals,
}: Readonly<{ totals: MonthTotals }>) {
    return (
        <>
            <span className={CHIP_BASE}>Runs {totals.runs}</span>
            <span className={CHIP_BASE}>
                Distance {totals.km.toFixed(1)} km
            </span>
            {totals.trimp !== null && (
                <span className={CHIP_BASE}>
                    TRIMP {Math.round(totals.trimp)}
                </span>
            )}
        </>
    );
}
