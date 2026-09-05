import type { SeasonSummaryWeek } from '@/lib/plan';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { cardVariants } from '@/lib/variants';

/**
 * A run of weeks the timeline holds behind one summary row — the stretch
 * already behind the athlete in this phase, or every phase still ahead — so
 * the season reads as a season rather than twenty stacked week cards.
 */
export default function WeekCluster({
    weeks,
    label,
    isLast,
    onExpand,
}: Readonly<{
    weeks: SeasonSummaryWeek[];
    label: string;
    isLast: boolean;
    onExpand: () => void;
}>) {
    const totalKm = weeks.reduce((sum, w) => sum + w.planned_km, 0);
    const totalSessions = weeks.reduce((sum, w) => sum + w.sessions, 0);
    const allDone = weeks.every((w) => w.type === 'history');

    return (
        <div className="flex gap-3">
            <div className="flex w-3 flex-none flex-col items-center">
                <span
                    aria-hidden
                    className="z-10 flex size-5 flex-none items-center justify-center rounded-full border-2 border-dashed border-border-strong bg-card text-text-2"
                >
                    <Icon icon="mdi:dots-horizontal" className="size-3" />
                </span>
                {!isLast && (
                    <span
                        aria-hidden
                        className={cn(
                            'mt-1 w-0.5 flex-1 rounded-full',
                            allDone ? 'bg-horizon' : 'bg-border-strong',
                        )}
                    />
                )}
            </div>
            <button
                type="button"
                onClick={onExpand}
                className={cn(
                    cardVariants({ padding: 'none' }),
                    'focus-ring mb-3 flex min-w-0 flex-1 items-center justify-between gap-3 border-dashed border-border-strong px-4 py-3.5 text-left',
                )}
            >
                <span>
                    <span className="block text-sm font-semibold text-foreground">
                        {label}
                    </span>
                    <span className="mt-0.5 block text-label-micro text-text-3">
                        {Math.round(totalKm)} km · {totalSessions} sessions
                    </span>
                </span>
                <span className="flex-none text-label-micro text-horizon-ink">
                    Show
                </span>
            </button>
        </div>
    );
}
