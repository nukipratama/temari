import { cn } from '@/lib/cn';
import { type Consistency } from '@/pages/Activities/calendarBars';

/**
 * The header's consistency line, e.g. "22 runs · 194 km · ran 21/30 days ·
 * longest streak 10". Split into two `whitespace-nowrap` halves joined by a
 * breakable space, so a narrow viewport wraps between phrases rather than
 * mid-phrase, and neither half can end on a dangling `·`.
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
            <span className="whitespace-nowrap">
                {stats.runs} run{stats.runs === 1 ? '' : 's'} ·{' '}
                {Math.round(stats.km)} km
            </span>{' '}
            <span className="whitespace-nowrap">
                · ran {stats.ranDays}/{stats.daysInMonth} days · longest streak{' '}
                {stats.longestStreak}
            </span>
        </p>
    );
}
