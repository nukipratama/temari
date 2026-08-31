import type { FormDataConvertible } from '@inertiajs/core';

import { Head, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useRef, useState } from 'react';

import type { AnalysisPayload, PlanSessionSegment } from '@/types/inertia';

import CoachMark from '@/components/onboarding/CoachMark';
import DaySegments from '@/components/plan/DaySegments';
import SeasonPhaseBar, {
    type SeasonSummaryWeek,
} from '@/components/plan/SeasonPhaseBar';
import SeasonTrack from '@/components/plan/SeasonTrack';
import SeasonWeekTimeline from '@/components/plan/SeasonWeekTimeline';
import PlanRaceTabs from '@/components/race/PlanRaceTabs';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import TemariProto, { type SeasonPhase } from '@/components/temari/TemariProto';
import { Card } from '@/components/ui/card';
import Chip, { type ChipTone } from '@/components/ui/Chip';
import EmptyPanel from '@/components/ui/EmptyPanel';
import Eyebrow from '@/components/ui/Eyebrow';
import GoalCard, { type Goal } from '@/components/ui/GoalCard';
import { Icon } from '@/components/ui/Icon';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import { useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { fadeInUp, staggerContainer } from '@/lib/motion';
import {
    formatDurationHMS,
    formatNaiveIdDate,
    formatPace,
    todayLocalIso,
} from '@/lib/pace';
import { currentSeasonPhase } from '@/lib/seasonPhase';
import { inputVariants, outlineChipVariants } from '@/lib/variants';

interface SeasonSummary {
    starts_at: string;
    ends_at: string;
    week_index: number;
    total_weeks: number;
    is_race_oriented: boolean;
    tiers_kept_from_past_seasons: number;
    goals: Goal[];
}

interface PlanDay {
    id: number;
    date: string;
    phase: string;
    session_type: string;
    segments: PlanSessionSegment[];
    distance_km: number;
    pinned: boolean;
    skipped: boolean;
    status: string;
    compliance_score: number | null;
    ran_anyway: boolean;
    clamp_note: string | null;
}

interface PlanWeek {
    week_start: string;
    phase: string;
    type: 'history' | 'current' | 'lookahead';
    days: PlanDay[];
}

interface PlanAdaptation {
    reason: string;
    headline: string;
    detail: string;
    deload: boolean;
}

interface PlanNarration {
    /** Keyed by date (Y-m-d) — only the current week's 7 days are ever requested. */
    days: Record<string, AnalysisPayload>;
    week: AnalysisPayload | null;
    season: AnalysisPayload | null;
}

interface PlanProps {
    race: { race_date: string; name: string | null } | null;
    sessionsPerWeek: number;
    weeks: PlanWeek[];
    season: SeasonSummary;
    seasonSummary?: SeasonSummaryWeek[];
    adaptation: PlanAdaptation | null;
    /** Served from App\Support\TrainingDisclaimer, shared with the legal pages. */
    disclaimerHeadline: string;
    disclaimer: string;
    planNarration?: PlanNarration;
    /** Seconds left before Regenerate may run again, or null when it's free to click. */
    regenerateCooldownSeconds?: number | null;
}

const PLAN_NARRATION_DEFAULT: PlanNarration = {
    days: {},
    week: null,
    season: null,
};

const STATUS_LABEL: Record<string, string> = {
    done: 'Done',
    partial: 'Partial',
    missed: 'Missed',
    overreached: 'Overreached',
    skip: 'Skipped',
};

// Mirrors WeekPlanWidget's own credited/missed/skip convention on Home, so a
// status reads the same color on both pages.
const STATUS_TONE: Record<string, string> = {
    done: 'text-horizon-ink',
    partial: 'text-horizon-ink',
    overreached: 'text-horizon-ink',
    missed: 'text-ember-ink',
    skip: 'text-text-3',
};

const SESSION_TYPE_LABEL: Record<string, string> = {
    easy: 'Easy',
    long: 'Long run',
    tempo: 'Tempo',
    interval: 'Interval',
    rest: 'Rest',
};

const SESSION_TYPE_ICON: Record<string, string> = {
    easy: 'mdi:feather',
    long: 'mdi:feather',
    tempo: 'mdi:fire',
    interval: 'mdi:fire',
    rest: 'mdi:bed',
};

export const PHASE_LABEL: Record<string, string> = {
    base: 'Base',
    build: 'Build',
    peak: 'Peak',
    taper: 'Taper',
    deload: 'Deload',
};

export const PHASE_TONE: Record<string, ChipTone> = {
    base: 'neutral',
    build: 'sky',
    peak: 'horizon',
    taper: 'horizon',
    deload: 'neutral',
};

const ADAPTATION_DOT: Record<string, string> = {
    steady: 'bg-leaf',
    low_readiness: 'bg-sky',
    high_monotony: 'bg-sky',
    high_strain: 'bg-sky',
    missed_week: 'bg-sky',
    behind_race_pace: 'bg-horizon',
    ahead_of_race_pace: 'bg-horizon',
};

// The mascot's thread coverage builds up as the season progresses — deload
// weeks pause accretion rather than reset it, so a deload week borrows the
// last non-deload phase's coverage instead of rendering its own.
const SEASON_VISUAL_CAPTION: Record<SeasonPhase, string> = {
    base: 'Thread just getting started, sparse and loosely wound.',
    build: 'Coverage building, bands starting to lock in.',
    peak: 'Fully wound, the most intricate the pattern gets.',
    taper: 'Pattern held at full coverage, with a rested shine.',
};

function paceLabel(day: PlanDay): string | null {
    const core = day.segments.find(
        (s) => s.key === 'main' || s.key === 'interval',
    );
    if (core?.pace_sec_per_km == null) return null;
    return `${formatPace(core.pace_sec_per_km)}/km`;
}

export default function Plan({
    race,
    sessionsPerWeek,
    weeks,
    season,
    seasonSummary = [],
    adaptation,
    disclaimerHeadline,
    disclaimer,
    planNarration = PLAN_NARRATION_DEFAULT,
    regenerateCooldownSeconds = null,
}: Readonly<PlanProps>) {
    const [regenerating, setRegenerating] = useState(false);
    const scheduleRef = useRef<HTMLDivElement>(null);
    const today = todayLocalIso();
    const seasonPhase = currentSeasonPhase(weeks);
    const regenerateCooldown = useCooldownCountdown(regenerateCooldownSeconds);
    const regenerateCooling = regenerateCooldown > 0;

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

    const toggleSkip = (day: PlanDay) =>
        patchDay(day, { skipped: !day.skipped });

    const toggleBlock = (day: PlanDay) => {
        patchDay(day, {
            session_type: day.session_type === 'rest' ? 'easy' : 'rest',
        });
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
                            <h1 className="font-serif text-quote-lg text-foreground italic">
                                the weeks ahead.
                            </h1>
                            <p className="mt-2 max-w-xl font-sans text-sm leading-relaxed text-text-2">
                                {race
                                    ? `Built around ${race.name ?? 'your race'} on ${formatNaiveIdDate(race.race_date, 'long')}, about ${sessionsPerWeek} sessions a week.`
                                    : `No race set yet, so this cycles a steady build-and-deload rhythm, about ${sessionsPerWeek} sessions a week.`}{' '}
                                <Link
                                    href="/race"
                                    className="underline underline-offset-2 hover:text-foreground"
                                >
                                    {race ? 'Change your race' : 'Set a race'}
                                </Link>
                            </p>
                        </div>
                        <PillButton
                            tone="sky"
                            data-coachmark="plan-regenerate"
                            onClick={regenerate}
                            disabled={regenerating || regenerateCooling}
                        >
                            {regenerating
                                ? 'Replanning…'
                                : regenerateCooling
                                  ? formatDurationHMS(regenerateCooldown)
                                  : 'Regenerate'}
                        </PillButton>
                    </div>
                </header>

                <section className="mt-10" data-testid="plan-adaptation">
                    {adaptation && (
                        <Card className="px-4 py-4">
                            <SectionLabel
                                dot
                                dotClass={
                                    ADAPTATION_DOT[adaptation.reason] ??
                                    (adaptation.deload
                                        ? 'bg-sky'
                                        : 'bg-horizon')
                                }
                                className="mb-2"
                            >
                                This week
                            </SectionLabel>
                            <p className="font-serif text-headline-sm italic text-foreground">
                                {adaptation.headline}
                            </p>
                            <p className="mt-2 text-sm leading-relaxed text-text-2">
                                {adaptation.detail}
                            </p>
                            {planNarration.week && (
                                <div className="mt-3 border-t border-border/60 pt-3">
                                    <AnalysisStatus
                                        analysis={planNarration.week}
                                        inertiaReloadProps={['planNarration']}
                                        size="sm"
                                        showTimestamp={false}
                                    />
                                </div>
                            )}
                        </Card>
                    )}
                    <Card className={cn('px-4 py-3', adaptation && 'mt-3')}>
                        <p className="text-label-micro text-text-2">
                            {disclaimerHeadline}
                        </p>
                        <p className="mt-2 text-sm leading-relaxed text-text-2">
                            {disclaimer}
                        </p>
                        <Link
                            href="/training-disclaimer"
                            className="focus-ring mt-2 inline-block text-sm text-text-2 underline underline-offset-2 hover:text-foreground"
                        >
                            What the plan can and cannot see
                        </Link>
                    </Card>
                </section>

                <section className="mt-10">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <SectionLabel>
                            Season · Week {season.week_index} of{' '}
                            {season.total_weeks}
                        </SectionLabel>
                        <Link
                            href="/trends"
                            className="text-xs text-text-2 underline underline-offset-2 hover:text-foreground"
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
                            <p className="mt-1 text-xs text-text-2">
                                {SEASON_VISUAL_CAPTION[seasonPhase]}
                            </p>
                        </div>
                    </div>
                    {planNarration.season && (
                        <div className="mt-3">
                            <AnalysisStatus
                                analysis={planNarration.season}
                                inertiaReloadProps={['planNarration']}
                                size="sm"
                                showTimestamp={false}
                            />
                        </div>
                    )}
                    {season.goals.length > 0 && (
                        <div className="mt-4">
                            <SeasonTrack
                                earned={
                                    season.goals.filter((g) => g.is_completed)
                                        .length
                                }
                                total={season.goals.length}
                                endsAt={season.ends_at}
                                tiersKeptFromPastSeasons={
                                    season.tiers_kept_from_past_seasons
                                }
                            />
                        </div>
                    )}
                    <motion.div
                        data-coachmark="plan-season-goals"
                        initial="hidden"
                        animate="visible"
                        variants={staggerContainer}
                        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                    >
                        {season.goals.map((goal) => (
                            <motion.div
                                key={goal.id}
                                variants={fadeInUp}
                                className="h-full"
                            >
                                <GoalCard goal={goal} />
                            </motion.div>
                        ))}
                    </motion.div>
                </section>

                {seasonSummary.length > 0 && (
                    <section className="mt-10 flex flex-col gap-6">
                        <Card className="px-4 py-4">
                            <SectionLabel className="mb-3">
                                Season by phase
                            </SectionLabel>
                            <SeasonPhaseBar weeks={seasonSummary} />
                        </Card>
                        <Card className="px-4 py-2">
                            <SectionLabel className="mb-1 px-1 pt-2">
                                Week by week
                            </SectionLabel>
                            <SeasonWeekTimeline weeks={seasonSummary} />
                        </Card>
                    </section>
                )}

                {weeks.length === 0 && (
                    <EmptyPanel
                        pose="proud"
                        title="No plan yet."
                        body="Hit Regenerate and Temari will lay out the weeks ahead."
                        className="mt-10"
                    />
                )}

                <div
                    ref={scheduleRef}
                    data-coachmark="plan-week-schedule"
                    className="mt-10 flex flex-col gap-10"
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
                                                className={cn(
                                                    'flex flex-wrap items-center justify-between gap-3 px-4 py-3',
                                                    day.date === today &&
                                                        'border border-horizon',
                                                )}
                                            >
                                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                                    <span className="w-24 shrink-0 font-mono text-xs font-semibold uppercase tracking-wider text-text-3">
                                                        {formatNaiveIdDate(
                                                            day.date,
                                                            'short',
                                                        )}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-semibold text-foreground">
                                                            <Icon
                                                                icon={
                                                                    SESSION_TYPE_ICON[
                                                                        day
                                                                            .session_type
                                                                    ] ??
                                                                    'mdi:feather'
                                                                }
                                                                width={13}
                                                                height={13}
                                                                className="mr-1 inline-block align-baseline text-text-3"
                                                            />
                                                            {SESSION_TYPE_LABEL[
                                                                day.session_type
                                                            ] ??
                                                                day.session_type}{' '}
                                                            {day.session_type !==
                                                                'rest' && (
                                                                <span className="ml-1.5 font-normal text-text-2">
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
                                                                <Icon
                                                                    icon="mdi:pin"
                                                                    width={13}
                                                                    height={13}
                                                                    role="img"
                                                                    aria-label="Pinned"
                                                                    className="ml-1.5 inline-block align-baseline text-text-3"
                                                                />
                                                            )}
                                                            {day.skipped && (
                                                                <Icon
                                                                    icon="mdi:close-circle-outline"
                                                                    width={13}
                                                                    height={13}
                                                                    role="img"
                                                                    aria-label="Skipped"
                                                                    className="ml-1.5 inline-block align-baseline text-text-3"
                                                                />
                                                            )}
                                                        </p>
                                                        <DaySegments
                                                            segments={
                                                                day.segments
                                                            }
                                                        />
                                                        {day.clamp_note && (
                                                            <p className="mt-0.5 text-xs italic text-text-2">
                                                                {day.clamp_note}
                                                            </p>
                                                        )}
                                                        {week.type ===
                                                            'history' &&
                                                            day.session_type !==
                                                                'rest' && (
                                                                <p
                                                                    className={cn(
                                                                        'mt-0.5 text-xs',
                                                                        STATUS_TONE[
                                                                            day
                                                                                .status
                                                                        ] ??
                                                                            'text-text-3',
                                                                    )}
                                                                >
                                                                    {STATUS_LABEL[
                                                                        day
                                                                            .status
                                                                    ] ?? ''}
                                                                    {day.compliance_score !=
                                                                        null &&
                                                                        ` · ${day.compliance_score}%`}
                                                                </p>
                                                            )}
                                                        {week.type ===
                                                            'history' &&
                                                            day.session_type ===
                                                                'rest' &&
                                                            day.ran_anyway && (
                                                                <p className="mt-0.5 text-xs text-text-3">
                                                                    Ran anyway
                                                                </p>
                                                            )}
                                                        {week.type ===
                                                            'current' &&
                                                            planNarration.days[
                                                                day.date
                                                            ] && (
                                                                <div className="mt-1">
                                                                    <AnalysisStatus
                                                                        analysis={
                                                                            planNarration
                                                                                .days[
                                                                                day
                                                                                    .date
                                                                            ]
                                                                        }
                                                                        inertiaReloadProps={[
                                                                            'planNarration',
                                                                        ]}
                                                                        size="sm"
                                                                        showTimestamp={
                                                                            false
                                                                        }
                                                                        allowReanalyze={
                                                                            false
                                                                        }
                                                                    />
                                                                </div>
                                                            )}
                                                    </div>
                                                </div>

                                                {editable && (
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                toggleBlock(day)
                                                            }
                                                            className={outlineChipVariants()}
                                                        >
                                                            {day.session_type ===
                                                            'rest'
                                                                ? 'Restore'
                                                                : 'Block'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                toggleSkip(day)
                                                            }
                                                            className={outlineChipVariants(
                                                                {
                                                                    selected:
                                                                        day.skipped,
                                                                },
                                                            )}
                                                        >
                                                            {day.skipped
                                                                ? 'Unskip'
                                                                : 'Skip'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                togglePin(day)
                                                            }
                                                            className={outlineChipVariants(
                                                                {
                                                                    selected:
                                                                        day.pinned,
                                                                },
                                                            )}
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
                                                            className={cn(
                                                                inputVariants({
                                                                    size: 'sm',
                                                                }),
                                                                'w-auto',
                                                            )}
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                deleteDay(day)
                                                            }
                                                            aria-label={`Delete ${day.date}`}
                                                            className={cn(
                                                                outlineChipVariants(),
                                                                'hover:border-ember hover:text-ember-ink',
                                                            )}
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
                <CoachMark
                    id="plan-week-schedule"
                    anchorRef={scheduleRef}
                    placement="top"
                    title="The week's yours"
                    body="Tap any upcoming day to swap the session, move it, or take it off."
                />
            </PageContainer>
        </>
    );
}

Plan.layout = appLayout;
