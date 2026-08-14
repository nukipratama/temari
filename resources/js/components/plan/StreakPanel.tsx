import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formatNaiveIdDate } from '@/lib/pace';

export interface StreakSummary {
    weeks: number;
    rest_weeks_held: number;
    rest_weeks_cap: number;
    /** Null once the held rest weeks are at their cap, since no more can arrive. */
    weeks_to_next_rest_week: number | null;
    ran_this_week: boolean;
    week_ends_on: string;
    last_forgiven_week: string | null;
}

function plural(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

function stakes(streak: StreakSummary): string {
    if (streak.weeks === 0) {
        return `Nothing at stake this week. One run before ${formatNaiveIdDate(streak.week_ends_on, 'short')} starts a new one.`;
    }

    if (streak.ran_this_week) {
        return 'You have already run this week, so this week counts.';
    }

    const closes = formatNaiveIdDate(streak.week_ends_on, 'short');

    if (streak.rest_weeks_held > 0) {
        return `This week closes ${closes} with nothing logged. If it stays that way, a rest week absorbs it and the count holds at ${streak.weeks}.`;
    }

    return `This week closes ${closes} with nothing logged. If it stays that way, the count goes back to zero.`;
}

function restWeeksNote(streak: StreakSummary): string {
    if (streak.rest_weeks_held > 0) {
        return `${plural(streak.rest_weeks_held, 'week')} off already forgiven. Nothing to play: Temari uses one the moment a week closes empty.`;
    }

    return 'A week off does not have to cost you the streak. Temari sets one aside as the training cycle comes round, and uses it without asking.';
}

/**
 * The weekly streak: what it is worth, what the open week is doing to it, and
 * the rest weeks that absorb a runless one.
 */
export default function StreakPanel({
    streak,
}: Readonly<{ streak: StreakSummary }>) {
    const nextRestWeek = streak.weeks_to_next_rest_week;

    return (
        <Card padding="card">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <SectionLabel dot dotClass="bg-leaf" className="mb-0">
                    Weekly Streak
                </SectionLabel>
                <p className="font-mono text-sm tabular-nums text-ink">
                    {streak.weeks === 0 ? (
                        <span className="text-ink-3">no streak</span>
                    ) : (
                        <>
                            {streak.weeks}{' '}
                            <span className="text-ink-3">
                                {streak.weeks === 1 ? 'week' : 'weeks'}
                            </span>
                        </>
                    )}
                </p>
            </div>

            <p className="mt-3 text-sm leading-relaxed text-ink-2">
                {stakes(streak)}
            </p>

            {streak.last_forgiven_week !== null && (
                <p className="mt-2 text-sm leading-relaxed text-ink-2">
                    The week ending{' '}
                    {formatNaiveIdDate(streak.last_forgiven_week, 'short')} was
                    forgiven: it bridged the streak without counting toward it.
                </p>
            )}

            <div className="mt-4 border-t border-line pt-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Eyebrow token="micro" tone="ink-3">
                        Rest Weeks
                    </Eyebrow>
                    <div
                        role="img"
                        aria-label={`${plural(streak.rest_weeks_held, 'rest week')} in hand, of ${streak.rest_weeks_cap}`}
                        className="flex items-center gap-1.5"
                    >
                        {Array.from(
                            { length: streak.rest_weeks_cap },
                            (_, index) => (
                                <span
                                    key={index}
                                    className={cn(
                                        'h-2.5 w-2.5 rounded-full',
                                        index < streak.rest_weeks_held
                                            ? 'bg-leaf'
                                            : 'border border-line-strong',
                                    )}
                                />
                            ),
                        )}
                    </div>
                </div>
                <p className="mt-2 text-sm leading-relaxed text-ink-2">
                    {restWeeksNote(streak)}
                </p>
                {nextRestWeek !== null && streak.weeks > 0 && (
                    <Eyebrow token="micro" tone="ink-3" className="mt-2">
                        Keep it going and the next one lands at week{' '}
                        {streak.weeks + nextRestWeek}
                    </Eyebrow>
                )}
            </div>
        </Card>
    );
}
