import { Link } from '@inertiajs/react';

import { type StreakSummary } from '@/components/plan/StreakPanel';
import { Card } from '@/components/ui/card';
import Eyebrow from '@/components/ui/Eyebrow';
import { type Goal } from '@/components/ui/GoalCard';
import ProgressBar from '@/components/ui/ProgressBar';
import SectionLabel from '@/components/ui/SectionLabel';
import { cn } from '@/lib/cn';
import { formatGoalNumber, goalProgressRatio } from '@/lib/goalProgress';
import { formatShortDateId } from '@/lib/pace';

export interface SeasonSummary {
    starts_at: string;
    ends_at: string;
    week_index: number;
    total_weeks: number;
    is_race_oriented: boolean;
    tiers_kept_from_past_seasons: number;
    goals: Goal[];
}

interface SeasonStreakPanelProps {
    /** Null until the user has visited Plan at least once — this panel never creates a season. */
    season: SeasonSummary | null;
    streak: StreakSummary;
}

function plural(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

/**
 * Season and streak already live on the Plan page — this surfaces the same
 * real data compactly on Profile, so the Me tab carries the full picture
 * rather than a re-derived one.
 */
export default function SeasonStreakPanel({
    season,
    streak,
}: Readonly<SeasonStreakPanelProps>) {
    const isLive = streak.weeks > 0 && streak.ran_this_week;

    return (
        <section className="mt-10">
            <SectionLabel>Season &amp; streak</SectionLabel>
            <Card className="mt-3 px-4 py-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-lg bg-line/20 p-4">
                        <div className="flex items-baseline justify-between">
                            <Eyebrow token="micro" tone="ink-3">
                                Streak
                            </Eyebrow>
                            {isLive && (
                                <span className="rounded-full bg-horizon/[0.18] px-2 py-0.5 text-label-micro text-horizon-ink">
                                    Live
                                </span>
                            )}
                        </div>
                        <p className="mt-1 font-mono text-2xl tabular-nums text-foreground">
                            {streak.weeks}{' '}
                            <span className="text-xs font-semibold text-text-3">
                                {streak.weeks === 1 ? 'week' : 'weeks'} running
                            </span>
                        </p>
                        <div
                            role="img"
                            aria-label={`${plural(streak.rest_weeks_held, 'rest week')} in hand, of ${streak.rest_weeks_cap}`}
                            className="mt-3 flex gap-1.5"
                        >
                            {Array.from(
                                { length: streak.rest_weeks_cap },
                                (_, index) => (
                                    <span
                                        key={index}
                                        className={cn(
                                            'h-2.5 w-2.5 rounded-full',
                                            index < streak.rest_weeks_held
                                                ? 'bg-horizon'
                                                : 'border border-border-strong',
                                        )}
                                    />
                                ),
                            )}
                        </div>
                        <p className="mt-2 text-xs leading-snug text-text-3">
                            A rest week forgives one missed week without
                            breaking the streak.
                        </p>
                    </div>

                    <div className="rounded-lg bg-line/20 p-4">
                        <Eyebrow token="micro" tone="ink-3">
                            Season
                        </Eyebrow>
                        {season ? (
                            <>
                                <p className="mt-1 text-sm text-text-2">
                                    {formatShortDateId(season.starts_at)}–
                                    {formatShortDateId(season.ends_at)} ·{' '}
                                    {season.is_race_oriented
                                        ? 'race-oriented'
                                        : 'self-scaled'}
                                </p>
                                <div className="mt-3 flex flex-col gap-3">
                                    {season.goals.map((goal) => (
                                        <div key={goal.id}>
                                            <div className="mb-1 flex items-baseline justify-between text-xs">
                                                <span className="font-semibold text-text-2">
                                                    {goal.title}
                                                </span>
                                                <span className="font-mono text-text-3">
                                                    {formatGoalNumber(
                                                        goal.current,
                                                    )}
                                                    /
                                                    {formatGoalNumber(
                                                        goal.target,
                                                    )}{' '}
                                                    {goal.unit}
                                                </span>
                                            </div>
                                            <ProgressBar
                                                value={goalProgressRatio(
                                                    goal.current,
                                                    goal.target,
                                                )}
                                                tone={
                                                    goal.is_completed
                                                        ? 'horizon'
                                                        : 'sky'
                                                }
                                                size="sm"
                                                ariaLabel={`${goal.title}: ${formatGoalNumber(goal.current)}/${formatGoalNumber(goal.target)} ${goal.unit}`}
                                            />
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <p className="mt-1 text-sm leading-relaxed text-text-2">
                                No season yet.{' '}
                                <Link
                                    href="/plan"
                                    className="focus-ring underline underline-offset-2 hover:text-foreground"
                                >
                                    Start one on Plan
                                </Link>
                                .
                            </p>
                        )}
                    </div>
                </div>
                {season && (
                    <p className="mt-4 text-xs text-text-3">
                        Week {season.week_index} of {season.total_weeks}
                        {season.tiers_kept_from_past_seasons > 0 &&
                            ` · ${plural(season.tiers_kept_from_past_seasons, 'tier')} kept from earlier seasons`}
                    </p>
                )}
            </Card>
        </section>
    );
}
