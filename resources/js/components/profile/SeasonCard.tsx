import { Link } from '@inertiajs/react';

import type { SeasonSummaryWeek } from '@/lib/plan';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import LegacyCard from '@/components/ui/LegacyCard';
import { cn } from '@/lib/cn';
import { formatNaiveMonthDayId } from '@/lib/pace';
import { PHASE_LABEL, phasesOf } from '@/lib/plan';

export interface SeasonGoal {
    id: number;
    title: string;
    current: number;
    target: number;
    unit: string;
    is_completed: boolean;
}

export interface ProfileSeason {
    starts_at: string;
    ends_at: string;
    week_index: number;
    total_weeks: number;
    goals: SeasonGoal[];
}

/**
 * The season in one card: which phase the athlete is in, the phase arc as a
 * segmented bar, and a single goal line. Decision P24 — the five-row season &
 * streak panel this replaces was deleted by `PP3`.
 *
 * The goal shown is the first one still open, so the line tracks what is
 * actually being worked toward rather than the first row the resolver happens
 * to return.
 */
export default function SeasonCard({
    season,
    weeks,
}: Readonly<{ season: ProfileSeason | null; weeks: SeasonSummaryWeek[] }>) {
    return (
        <LegacyCard as="section">
            <Eyebrow token="micro" tone="ink-3">
                Season
            </Eyebrow>
            {season === null ? (
                <p className="mt-2 text-sm leading-relaxed text-text-2">
                    No season yet.{' '}
                    <Link
                        href="/plan"
                        className="focus-ring inline-flex items-center gap-0.5 font-semibold text-horizon-ink"
                    >
                        Start one on Plan
                        <Icon
                            icon="mdi:arrow-right"
                            width={12}
                            height={12}
                            aria-hidden
                        />
                    </Link>
                </p>
            ) : (
                <SeasonBody season={season} weeks={weeks} />
            )}
        </LegacyCard>
    );
}

function SeasonBody({
    season,
    weeks,
}: Readonly<{ season: ProfileSeason; weeks: SeasonSummaryWeek[] }>) {
    const phases = phasesOf(weeks);
    const current = phases.find((p) => p.state === 'current');
    const goal = season.goals.find((g) => !g.is_completed) ?? season.goals[0];
    const goalPct =
        goal && goal.target > 0
            ? Math.min(100, Math.round((goal.current / goal.target) * 100))
            : null;

    return (
        <>
            <p className="mt-2 text-sm text-text-2">
                {`${current ? `${PHASE_LABEL[current.key] ?? current.key} · ` : ''}${formatNaiveMonthDayId(season.starts_at)} – ${formatNaiveMonthDayId(season.ends_at)}`}
            </p>

            {phases.length > 0 && (
                <div className="mt-2 flex gap-1">
                    {phases.map((phase) => (
                        <div
                            key={phase.key}
                            className={cn(
                                'flex h-4 flex-1 items-center justify-center overflow-hidden rounded-xs',
                                phase.state === 'current' &&
                                    'bg-[repeating-linear-gradient(115deg,var(--color-horizon),var(--color-horizon)_3px,var(--color-horizon-deep)_3px,var(--color-horizon-deep)_6px)]',
                                phase.state === 'done' && 'bg-horizon',
                                phase.state === 'upcoming' &&
                                    'bg-border-strong',
                            )}
                        >
                            <span
                                className={cn(
                                    'text-label-micro',
                                    phase.state === 'upcoming'
                                        ? 'text-text-2'
                                        : 'text-sky',
                                )}
                            >
                                {PHASE_LABEL[phase.key] ?? phase.key}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            {goal && goalPct !== null && (
                <>
                    <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-border-strong">
                        <div
                            className="h-full bg-horizon"
                            style={{ width: `${goalPct}%` }}
                        />
                    </div>
                    <p className="mt-1.5 text-label-micro text-text-2">
                        {`${goalPct}% · ${goal.title}`}
                    </p>
                </>
            )}
        </>
    );
}
