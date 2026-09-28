import { cn } from '@/lib/cn';
import { type Consistency } from '@/pages/Activities/calendarBars';

/**
 * The header's consistency line, e.g. "22 runs · 194 km · ran 21/30 days ·
 * longest streak 10". Split into two `whitespace-nowrap` groups, stacked
 * below `sm` and joined inline by a `·` separator from `sm` up, so a narrow
 * viewport wraps between phrases rather than on a dangling `·`.
 */
export default function ConsistencyLine({
    stats,
    className,
}: Readonly<{ stats: Consistency; className?: string }>) {
    return (
        <p
            className={cn(
                'text-center font-mono text-xs tabular-nums text-text-2',
                className,
            )}
        >
            <span className="block whitespace-nowrap sm:inline">
                {stats.runs} run{stats.runs === 1 ? '' : 's'} ·{' '}
                {Math.round(stats.km)} km
            </span>
            <span className="hidden sm:inline"> · </span>
            <span className="block whitespace-nowrap sm:inline">
                ran {stats.ranDays}/{stats.daysInMonth} days · longest streak{' '}
                {stats.longestStreak}
            </span>
        </p>
    );
}
