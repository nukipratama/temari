import type { FormDataConvertible } from '@inertiajs/core';

import { Head, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useState } from 'react';

import PlanRaceTabs from '@/components/race/PlanRaceTabs';
import TemariProto, { type SeasonPhase } from '@/components/temari/TemariProto';
import Card from '@/components/ui/Card';
import Chip, { type ChipTone } from '@/components/ui/Chip';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import ProgressBar from '@/components/ui/ProgressBar';
import SectionLabel from '@/components/ui/SectionLabel';
import { useCountUp } from '@/hooks/useCountUp';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { formatGoalNumber, goalProgressRatio } from '@/lib/goalProgress';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import { formatNaiveIdDate, formatPace, todayLocalIso } from '@/lib/pace';
import { currentSeasonPhase } from '@/lib/seasonPhase';

interface SeasonGoal {
    id: number;
    title: string;
    current: number;
    target: number;
    unit: string;
    is_completed: boolean;
}

interface SeasonSummary {
    starts_at: string;
    ends_at: string;
    week_index: number;
    total_weeks: number;
    is_race_oriented: boolean;
    goals: SeasonGoal[];
}

interface PlanDay {
    id: number;
    date: string;
    phase: string;
    session_type: string;
    distance_band: string;
    pace_band: string | null;
    pace_sec_per_km: number | null;
    distance_km: number;
    pinned: boolean;
    status: string;
    clamp_note: string | null;
}

interface PlanWeek {
    week_start: string;
    phase: string;
    type: 'history' | 'current' | 'lookahead';
    days: PlanDay[];
}

interface PlanProps {
    race: { race_date: string; name: string | null } | null;
    sessionsPerWeek: number;
    weeks: PlanWeek[];
    season: SeasonSummary;
}

const SESSION_TYPE_LABEL: Record<string, string> = {
    easy: 'Easy',
    long: 'Long run',
    tempo: 'Tempo',
    interval: 'Interval',
    rest: 'Rest',
};

const PHASE_LABEL: Record<string, string> = {
    base: 'Base',
    build: 'Build',
    peak: 'Peak',
    taper: 'Taper',
    deload: 'Deload',
};

const PHASE_TONE: Record<string, ChipTone> = {
    base: 'neutral',
    build: 'sky',
    peak: 'horizon',
    taper: 'horizon',
    deload: 'neutral',
};

const BAND_ORDER = ['short', 'medium', 'long'] as const;

// The mascot's thread coverage builds up as the season progresses — deload
// weeks pause accretion rather than reset it, so a deload week borrows the
// last non-deload phase's coverage instead of rendering its own.
const SEASON_VISUAL_CAPTION: Record<SeasonPhase, string> = {
    base: 'Thread just getting started — sparse and loosely wound.',
    build: 'Coverage building, bands starting to lock in.',
    peak: 'Fully wound — the most intricate the pattern gets.',
    taper: 'Pattern held at full coverage, with a rested shine.',
};

function paceLabel(day: PlanDay): string | null {
    if (day.pace_sec_per_km == null) return null;
    return `${formatPace(day.pace_sec_per_km)}/km`;
}

export default function Plan({
    race,
    sessionsPerWeek,
    weeks,
    season,
}: Readonly<PlanProps>) {
    const [regenerating, setRegenerating] = useState(false);
    const today = todayLocalIso();
    const seasonPhase = currentSeasonPhase(weeks);

    const regenerate = () => {
        router.post(
            '/plan/regenerate',
            {},
            {
                preserveScroll: true,
                onStart: () => setRegenerating(true),
                onFinish: () => setRegenerating(false),
            },
        );
    };

    const patchDay = (
        day: PlanDay,
        attributes: Record<string, FormDataConvertible>,
    ) => {
        router.patch(`/plan/sessions/${day.id}`, attributes, {
            preserveScroll: true,
        });
    };

    const togglePin = (day: PlanDay) => patchDay(day, { pinned: !day.pinned });

    const cycleBand = (day: PlanDay) => {
        const index = BAND_ORDER.indexOf(
            day.distance_band as (typeof BAND_ORDER)[number],
        );
        const next = BAND_ORDER[(index + 1) % BAND_ORDER.length];
        patchDay(day, { distance_band: next });
    };

    const toggleBlock = (day: PlanDay) => {
        if (day.session_type === 'rest') {
            patchDay(day, {
                session_type: 'easy',
                distance_band: 'medium',
                pace_band: 'easy',
            });
        } else {
            patchDay(day, { session_type: 'rest' });
        }
    };

    const moveDay = (day: PlanDay, newDate: string) => {
        if (newDate === '' || newDate === day.date) return;
        patchDay(day, { date: newDate });
    };

    const deleteDay = (day: PlanDay) => {
        router.delete(`/plan/sessions/${day.id}`, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Plan" />
            <PageContainer>
                <header className="flex flex-col gap-5">
                    <PlanRaceTabs active="plan" />
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Eyebrow
                                token="hero"
                                tone="ink-2"
                                className="mb-3.5"
                            >
                                Plan
                            </Eyebrow>
                            <h1 className="font-display text-display-lg text-ink">
                                The weeks ahead.
                            </h1>
                            <p className="mt-2 max-w-xl font-sans text-sm leading-relaxed text-ink-2">
                                {race
                                    ? `Built around ${race.name ?? 'your race'} on ${formatNaiveIdDate(race.race_date, 'long')}, about ${sessionsPerWeek} sessions a week.`
                                    : `No race set yet, so this cycles a steady build-and-deload rhythm, about ${sessionsPerWeek} sessions a week.`}{' '}
                                <Link
                                    href="/race"
                                    className="underline underline-offset-2 hover:text-ink"
                                >
                                    {race ? 'Change your race' : 'Set a race'}
                                </Link>
                            </p>
                        </div>
                        <PillButton
                            tone="sky"
                            data-coachmark="plan-regenerate"
                            onClick={regenerate}
                            disabled={regenerating}
                        >
                            {regenerating ? 'Replanning…' : 'Regenerate'}
                        </PillButton>
                    </div>
                </header>

                <section className="mt-8">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <SectionLabel>
                            Season · Week {season.week_index} of{' '}
                            {season.total_weeks}
                        </SectionLabel>
                        <Link
                            href="/badges"
                            className="text-xs text-ink-2 underline underline-offset-2 hover:text-ink"
                        >
                            Badge board
                        </Link>
                    </div>
                    <div className="mt-3 flex items-center gap-3">
                        <TemariProto
                            pose="observational"
                            size={56}
                            dropShadow={false}
                            seasonPhase={seasonPhase}
                        />
                        <div>
                            <Chip tone={PHASE_TONE[seasonPhase] ?? 'neutral'}>
                                {PHASE_LABEL[seasonPhase] ?? seasonPhase}
                            </Chip>
                            <p className="mt-1 text-xs text-ink-2">
                                {SEASON_VISUAL_CAPTION[seasonPhase]}
                            </p>
                        </div>
                    </div>
                    <motion.div
                        data-coachmark="plan-season-goals"
                        initial="hidden"
                        animate="visible"
                        variants={staggerContainer}
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                    >
                        {season.goals.map((goal) => (
                            <motion.div key={goal.id} variants={fadeInUp}>
                                <SeasonGoalCard goal={goal} />
                            </motion.div>
                        ))}
                    </motion.div>
                </section>

                {weeks.length === 0 && (
                    <EmptyPanel
                        pose="proud"
                        title="No plan yet."
                        body="Hit Regenerate and Temari will lay out the weeks ahead."
                        className="mt-8"
                    />
                )}

                <div
                    data-coachmark="plan-week-schedule"
                    className="mt-8 flex flex-col gap-8"
                >
                    {weeks.map((week) => (
                        <section key={week.week_start}>
                            <div className="flex items-center gap-2.5">
                                <SectionLabel>
                                    Week of{' '}
                                    {formatNaiveIdDate(week.week_start, 'long')}
                                </SectionLabel>
                                <Chip
                                    tone={PHASE_TONE[week.phase] ?? 'neutral'}
                                >
                                    {PHASE_LABEL[week.phase] ?? week.phase}
                                </Chip>
                                {week.type === 'history' && (
                                    <Chip tone="neutral">History</Chip>
                                )}
                            </div>

                            <motion.div
                                initial="hidden"
                                animate="visible"
                                variants={staggerContainer}
                                className="mt-3 flex flex-col gap-2"
                            >
                                {week.days.map((day) => {
                                    const editable =
                                        day.date >= today &&
                                        week.type !== 'history';

                                    return (
                                        <motion.div
                                            key={day.date}
                                            variants={fadeInUp}
                                        >
                                            <Card
                                                padding="sm"
                                                className={cn(
                                                    'flex flex-wrap items-center justify-between gap-3 shadow-sm',
                                                    day.date === today &&
                                                        'border-horizon',
                                                )}
                                            >
                                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                                    <span className="w-24 shrink-0 font-mono text-xs font-semibold uppercase tracking-wider text-ink-3">
                                                        {formatNaiveIdDate(
                                                            day.date,
                                                            'short',
                                                        )}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-semibold text-ink">
                                                            {SESSION_TYPE_LABEL[
                                                                day.session_type
                                                            ] ??
                                                                day.session_type}{' '}
                                                            {day.session_type !==
                                                                'rest' && (
                                                                <span className="ml-1.5 font-normal text-ink-2">
                                                                    {
                                                                        day.distance_km
                                                                    }{' '}
                                                                    km
                                                                    {paceLabel(
                                                                        day,
                                                                    ) &&
                                                                        ` · ${paceLabel(day)}`}
                                                                </span>
                                                            )}
                                                            {day.pinned && (
                                                                <span
                                                                    className="ml-1.5 text-ink-3"
                                                                    title="Pinned"
                                                                    aria-label="Pinned"
                                                                >
                                                                    📌
                                                                </span>
                                                            )}
                                                        </p>
                                                        {day.clamp_note && (
                                                            <p className="mt-0.5 text-xs italic text-ink-2">
                                                                {day.clamp_note}
                                                            </p>
                                                        )}
                                                        {week.type ===
                                                            'history' &&
                                                            day.session_type !==
                                                                'rest' && (
                                                                <p className="mt-0.5 text-xs text-ink-3">
                                                                    {day.status ===
                                                                    'done'
                                                                        ? 'Done'
                                                                        : day.status ===
                                                                            'missed'
                                                                          ? 'Missed'
                                                                          : ''}
                                                                </p>
                                                            )}
                                                    </div>
                                                </div>

                                                {editable && (
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        {day.session_type !==
                                                            'rest' && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    cycleBand(
                                                                        day,
                                                                    )
                                                                }
                                                                className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-horizon/60 hover:text-ink"
                                                            >
                                                                Resize
                                                            </button>
                                                        )}
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                toggleBlock(day)
                                                            }
                                                            className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-horizon/60 hover:text-ink"
                                                        >
                                                            {day.session_type ===
                                                            'rest'
                                                                ? 'Restore'
                                                                : 'Block'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                togglePin(day)
                                                            }
                                                            className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-horizon/60 hover:text-ink"
                                                        >
                                                            {day.pinned
                                                                ? 'Unpin'
                                                                : 'Pin'}
                                                        </button>
                                                        <input
                                                            type="date"
                                                            aria-label={`Move ${day.date}`}
                                                            min={today}
                                                            defaultValue={
                                                                day.date
                                                            }
                                                            onChange={(e) =>
                                                                moveDay(
                                                                    day,
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="focus-ring rounded-full border border-line bg-surface px-2 py-1 text-label-micro text-ink-3"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                deleteDay(day)
                                                            }
                                                            aria-label={`Delete ${day.date}`}
                                                            className="focus-ring rounded-full border border-line px-2.5 py-1 text-label-micro text-ink-3 hover:border-ember hover:text-ember"
                                                        >
                                                            Delete
                                                        </button>
                                                    </div>
                                                )}
                                            </Card>
                                        </motion.div>
                                    );
                                })}
                            </motion.div>
                        </section>
                    ))}
                </div>
            </PageContainer>
        </>
    );
}

function SeasonGoalCard({ goal }: Readonly<{ goal: SeasonGoal }>) {
    const current = useCountUp(goal.current);

    return (
        <Card
            padding="sm"
            className={cn(
                'flex flex-col gap-2 shadow-sm',
                goal.is_completed && 'border-horizon/30 bg-horizon/[0.06]',
            )}
        >
            <p className="text-sm font-semibold text-ink">{goal.title}</p>
            <div className="mt-auto">
                <div className="mb-1 flex items-baseline justify-between font-mono text-[11px] tabular-nums text-ink-3">
                    <span>
                        {formatGoalNumber(current)}
                        <span className="text-ink-3">/</span>
                        {formatGoalNumber(goal.target)}
                    </span>
                    <span>{goal.unit}</span>
                </div>
                <ProgressBar
                    value={goalProgressRatio(goal.current, goal.target)}
                    tone={goal.is_completed ? 'horizon' : 'sky'}
                    ariaLabel={`${goal.title}: ${formatGoalNumber(goal.current)}/${formatGoalNumber(goal.target)} ${goal.unit}`}
                />
            </div>
        </Card>
    );
}

Plan.layout = appLayout;
