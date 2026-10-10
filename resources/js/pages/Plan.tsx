import { Deferred, Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Clock, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import type {
    PlanDay,
    PlanNarration,
    PlanWeek,
    SeasonSummaryWeek,
} from '@/lib/plan';
import type { PlanRecalibrationState } from '@/types/inertia';

import AiOutageBanner from '@/components/AiOutageBanner';
import PlanHeading from '@/components/plan/PlanHeading';
import { PlanWeeksSkeleton } from '@/components/plan/PlanSkeleton';
import SeasonHeaderCard from '@/components/plan/SeasonHeaderCard';
import SeasonTimeline from '@/components/plan/SeasonTimeline';
import EmptyPanel from '@/components/ui/EmptyPanel';
import { Icon } from '@/components/ui/Icon';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import PillButton from '@/components/ui/PillButton';
import { useCooldownCountdown } from '@/hooks/useCooldownCountdown';
import { appLayout } from '@/layouts/appLayout';
import { cn } from '@/lib/cn';
import { readPlanSelectedDay } from '@/lib/navigationMemory';
import {
    formatDurationHMS,
    formatNaiveMonthDayId,
    useTodayIso,
} from '@/lib/pace';

interface SeasonSummary {
    starts_at: string;
    ends_at: string;
    week_index: number;
    total_weeks: number;
    under_ready_line: string | null;
}

interface PlanAdaptation {
    reason: string;
    headline: string;
    detail: string;
    deload: boolean;
}

interface PlanProps {
    race: { race_date: string; name: string | null } | null;
    sessionsPerWeek: number;
    weeks?: PlanWeek[];
    season: SeasonSummary | null;
    seasonSummary?: SeasonSummaryWeek[];
    seasonAdherencePct?: number | null;
    adaptation?: PlanAdaptation | null;
    /** Served from App\Support\TrainingDisclaimer, shared with the legal pages. */
    disclaimerLine: string;
    planNarration?: PlanNarration;
    /** Seconds left before Regenerate may run again, or null when it's free to click. */
    regenerateCooldownSeconds?: number | null;
    planRecalibration?: PlanRecalibrationState;
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/** The day Home's week card asked for, when it asked for a readable one. */
function requestedDay(): string | null {
    const day = new URLSearchParams(window.location.search).get('day');

    return day !== null && ISO_DATE.test(day) ? day : null;
}

const PLAN_NARRATION_DEFAULT: PlanNarration = {
    season: null,
};

export default function Plan({
    race,
    sessionsPerWeek,
    weeks,
    season,
    seasonSummary = [],
    seasonAdherencePct = null,
    adaptation = null,
    disclaimerLine,
    planNarration = PLAN_NARRATION_DEFAULT,
    regenerateCooldownSeconds = null,
    planRecalibration,
}: Readonly<PlanProps>) {
    const [regenerating, setRegenerating] = useState(false);
    const today = useTodayIso();
    const [focusDay] = useState(requestedDay);
    const [selectedDay] = useState(() => focusDay ?? readPlanSelectedDay());
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

    const moveSession = (day: PlanDay, toDate: string) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { date: toDate },
            { preserveScroll: true },
        );
    };

    const skipSession = (day: PlanDay) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { skipped: true },
            { preserveScroll: true },
        );
    };

    const unskipSession = (day: PlanDay) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { skipped: false, pinned: false },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Plan" />
            <AiOutageBanner />
            <PageContainer>
                <PlanHeading
                    action={
                        <PillButton
                            tone="muted"
                            size="xs"
                            className="flex-none"
                            onClick={regenerate}
                            disabled={regenerating || regenerateCooling}
                            aria-label={
                                regenerating
                                    ? 'replanning'
                                    : regenerateCooling
                                      ? `regenerate, next in ${formatDurationHMS(regenerateCooldown)}`
                                      : 'regenerate'
                            }
                        >
                            <Icon
                                icon={regenerateCooling ? Clock : RefreshCw}
                                className={cn(
                                    'size-3.5',
                                    regenerating && 'animate-spin',
                                )}
                                aria-hidden
                            />
                            {regenerateCooling && (
                                <span aria-hidden>
                                    {formatDurationHMS(regenerateCooldown)}
                                </span>
                            )}
                        </PillButton>
                    }
                />
                <p className="mt-1 mb-4 text-xs text-text-2">
                    {race
                        ? `${race.name ?? 'your race'} · ${formatNaiveMonthDayId(race.race_date)}`
                        : 'no race set · steady build and deload'}
                    {` · ${sessionsPerWeek} sessions a week · `}
                    <Link
                        href="/race"
                        className="focus-ring hit-area inline-flex items-center gap-0.5 font-semibold text-horizon-ink"
                    >
                        {race ? 'race goal' : 'set a race'}
                        <Icon
                            icon={ArrowRight}
                            className="size-3"
                            aria-hidden
                        />
                    </Link>
                </p>

                {planRecalibration?.pending && (
                    <div
                        role="status"
                        className="mb-4 flex items-start gap-2 rounded-md border border-border-strong bg-muted pad-panel"
                    >
                        <Icon
                            icon={RefreshCw}
                            className="mt-0.5 size-3.5 flex-none text-horizon-ink"
                            aria-hidden
                        />
                        <div>
                            <p className="text-xs font-semibold text-foreground">
                                rechecking your plan
                            </p>
                            <p className="mt-0.5 text-xs leading-relaxed text-text-2">
                                the current plan stays in place while your
                                latest HR zones are applied.
                            </p>
                        </div>
                    </div>
                )}

                <Deferred
                    data={[
                        'weeks',
                        'seasonSummary',
                        'seasonAdherencePct',
                        'adaptation',
                    ]}
                    fallback={<PlanWeeksSkeleton />}
                >
                    {() =>
                        weeks!.length === 0 || season === null ? (
                            <EmptyPanel
                                face
                                title="no plan yet."
                                body="hit regenerate and temari will lay out the weeks ahead."
                                className="mt-6"
                            />
                        ) : (
                            <LaneStack className="mt-6">
                                <SeasonHeaderCard
                                    weekIndex={season.week_index}
                                    totalWeeks={season.total_weeks}
                                    startsAt={season.starts_at}
                                    endsAt={season.ends_at}
                                    adherencePct={seasonAdherencePct}
                                    weeks={seasonSummary}
                                    narration={planNarration.season}
                                    underReadyLine={season.under_ready_line}
                                />
                                <SeasonTimeline
                                    weeks={seasonSummary}
                                    detailByWeekStart={Object.fromEntries(
                                        weeks!.map((week) => [
                                            week.week_start,
                                            week,
                                        ]),
                                    )}
                                    today={today}
                                    raceDate={race?.race_date ?? null}
                                    weekFocus={adaptation}
                                    focusDay={focusDay}
                                    selectedDay={selectedDay}
                                    onMove={moveSession}
                                    onSkip={skipSession}
                                    onUnskip={unskipSession}
                                />
                            </LaneStack>
                        )
                    }
                </Deferred>

                <footer className="mt-8 border-t border-border pt-3 text-xs text-text-3">
                    {disclaimerLine}{' '}
                    <Link
                        href="/training-disclaimer"
                        className="focus-ring hit-area text-text-2 underline underline-offset-2 hover:text-foreground"
                    >
                        the full disclaimer
                    </Link>
                </footer>
            </PageContainer>
        </>
    );
}

Plan.layout = appLayout;
