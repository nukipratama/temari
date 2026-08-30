import { motion } from 'framer-motion';
import { useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import { cn } from '@/lib/cn';
import { pressShrink } from '@/lib/motion';
import { formatNaiveIdDate } from '@/lib/pace';

export interface StreakSummaryLike {
    weeks: number;
    rest_weeks_held: number;
    rest_weeks_cap: number;
    ran_this_week: boolean;
    week_ends_on: string;
}

interface StreakBadgeProps {
    streak: StreakSummaryLike;
    className?: string;
}

function plural(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

function streakDetail(streak: StreakSummaryLike): string {
    if (streak.weeks === 0) {
        return `No active streak yet. One run before ${formatNaiveIdDate(streak.week_ends_on, 'short')} starts a new one.`;
    }
    const restNote =
        streak.rest_weeks_held > 0
            ? ` ${plural(streak.rest_weeks_held, 'rest week')} in hand to forgive a missed one.`
            : '';
    return `${plural(streak.weeks, 'week')} running with at least one logged run every week.${restNote}`;
}

/**
 * The week-grained lifetime streak (SeasonStreakSummaryBuilder::streakPayload())
 * as its own badge-board entry, ported onto the prototype's badge chip +
 * expand-on-tap pattern (TrendsScreen.tsx's FitnessPanel badges). Not
 * range-scoped like the charts above — it's a live current fact, so it sits
 * with Personal Bests instead of inside FitnessTrend's date-anchored
 * milestone timeline. Uses a medal glyph, not the flame reserved for the
 * prototype's tempo-session day-glyph convention elsewhere in the app.
 */
export default function StreakBadge({
    streak,
    className,
}: Readonly<StreakBadgeProps>) {
    const [open, setOpen] = useState(false);
    const hasStreak = streak.weeks > 0;

    return (
        <div
            className={cn(
                'flex flex-col gap-3 rounded-(--radius-panel) border border-border bg-card p-6 shadow-(--shadow-panel) sm:p-8',
                className,
            )}
        >
            <div>
                <p className="text-label-micro text-text-3">Badge board</p>
                <h2 className="mt-1 font-serif text-lg text-foreground">
                    Streak
                </h2>
            </div>

            <motion.button
                type="button"
                whileTap={pressShrink}
                aria-pressed={open}
                onClick={() => setOpen((prev) => !prev)}
                className={cn(
                    'inline-flex w-fit items-center gap-2 rounded-full border px-3 py-2 text-xs whitespace-nowrap transition-colors',
                    open
                        ? 'border-horizon-ink bg-horizon/25 text-foreground'
                        : 'border-border bg-popover text-text-2 hover:bg-cream-deep',
                )}
            >
                <Icon
                    icon="mdi:medal-outline"
                    className="size-3.5 text-rarity-uncommon-ink"
                    aria-hidden
                />
                <span className="font-semibold">
                    {hasStreak
                        ? `${plural(streak.weeks, 'week')} streak`
                        : 'No streak yet'}
                </span>
                {streak.ran_this_week && (
                    <span className="text-text-3">This week counts</span>
                )}
            </motion.button>

            {open && (
                <div className="rounded-(--radius-panel) border border-horizon-ink/30 bg-horizon/12 p-4">
                    <p className="text-sm text-text-2">
                        {streakDetail(streak)}
                    </p>
                </div>
            )}
        </div>
    );
}
