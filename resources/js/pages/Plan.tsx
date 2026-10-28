import { Deferred, Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';

import type { PlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';
import type {
    PlanRaceCard as PlanRaceCardPayload,
    PlanRecalibrationState,
    RaceOutcomeCandidate,
} from '@/types/inertia';

import AddRacePrompt from '@/components/plan/AddRacePrompt';
import PlanHeading from '@/components/plan/PlanHeading';
import PlanRaceCard from '@/components/plan/PlanRaceCard';
import { PlanWeeksSkeleton } from '@/components/plan/PlanSkeleton';
import RegenerateButton from '@/components/plan/RegenerateButton';
import SeasonHeaderCard from '@/components/plan/SeasonHeaderCard';
import SeasonTimeline from '@/components/plan/SeasonTimeline';
import EmptyPanel from '@/components/ui/EmptyPanel';
import { Icon } from '@/components/ui/Icon';
import LaneStack from '@/components/ui/LaneStack';
import PageContainer from '@/components/ui/PageContainer';
import { showToast } from '@/hooks/useToast';
import { appLayout } from '@/layouts/appLayout';
import { readPlanSelectedDay } from '@/lib/navigationMemory';
import { useTodayIso } from '@/lib/pace';
import { planEditLanded, weekdayLabel } from '@/lib/plan';

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
    raceCard: PlanRaceCardPayload | null;
    raceSupportedTimeSec?: number | null;
    raceOutcomeCandidate?: RaceOutcomeCandidate | null;
    sessionsPerWeek: number;
    weeks?: PlanWeek[];
    /** A week older than `weeks` holds, asked for with `?week=` on a partial reload. */
    pastWeek?: PlanWeek | null;
    season: SeasonSummary | null;
    seasonSummary?: SeasonSummaryWeek[];
    seasonAdherencePct?: number | null;
    adaptation?: PlanAdaptation | null;
    /** Served from App\Support\TrainingDisclaimer, shared with the legal pages. */
    disclaimerLine: string;
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

export default function Plan({
    race,
    raceCard,
    raceSupportedTimeSec,
    raceOutcomeCandidate,
    sessionsPerWeek,
    weeks = [],
    season,
    seasonSummary = [],
    seasonAdherencePct = null,
    adaptation = null,
    disclaimerLine,
    regenerateCooldownSeconds = null,
    planRecalibration,
}: Readonly<PlanProps>) {
    const today = useTodayIso();
    const [focusDay] = useState(requestedDay);
    const [selectedDay] = useState(() => focusDay ?? readPlanSelectedDay());

    const [pastWeeks, setPastWeeks] = useState<PlanWeek[]>([]);

    const detailByWeekStart = useMemo(
        () =>
            Object.fromEntries(
                [...pastWeeks, ...weeks].map((week) => [week.week_start, week]),
            ),
        [pastWeeks, weeks],
    );

    const loadPastWeek = (weekStart: string) =>
        new Promise<boolean>((resolve) => {
            router.reload({
                only: ['pastWeek'],
                data: { week: weekStart },
                preserveUrl: true,
                onSuccess: (page) => {
                    const week = (page.props as Partial<PlanProps>).pastWeek;
                    if (week) {
                        setPastWeeks((loaded) => [...loaded, week]);
                    }
                    resolve(Boolean(week));
                },
                onFinish: () => resolve(false),
            });
        });

    const moveSession = (day: PlanDay, toDate: string) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { date: toDate },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (planEditLanded(page)) {
                        showToast(`moved to ${weekdayLabel(toDate)}`);
                    }
                },
            },
        );
    };

    const skipSession = (day: PlanDay) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { skipped: true },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (planEditLanded(page)) {
                        showToast('skipped');
                    }
                },
            },
        );
    };

    const unskipSession = (day: PlanDay) => {
        router.patch(
            `/plan/sessions/${day.id}`,
            { skipped: false, pinned: false },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (planEditLanded(page)) {
                        showToast('restored');
                    }
                },
            },
        );
    };

    return (
        <>
            <Head title="Plan" />
            <PageContainer>
                <PlanHeading
                    action={
                        <RegenerateButton
                            cooldownSeconds={regenerateCooldownSeconds}
                        />
                    }
                />
                <p className="mt-1 mb-4 text-xs text-text-2">
                    {sessionsPerWeek} sessions a week
                </p>

                <PlanRaceCard
                    card={raceCard}
                    today={today}
                    supportedTimeSec={raceSupportedTimeSec}
                    candidate={raceOutcomeCandidate}
                    className="mb-4"
                />

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
                        weeks.length === 0 || season === null ? (
                            <>
                                <EmptyPanel
                                    face
                                    title="no plan yet."
                                    body="hit regenerate and temari will lay out the weeks ahead."
                                    className="mt-6"
                                />
                                {(raceCard === null ||
                                    raceCard.state === 'result_recorded') && (
                                    <AddRacePrompt className="mt-4" />
                                )}
                            </>
                        ) : (
                            <LaneStack className="mt-6">
                                <SeasonHeaderCard
                                    weekIndex={season.week_index}
                                    totalWeeks={season.total_weeks}
                                    adherencePct={seasonAdherencePct}
                                    weeks={seasonSummary}
                                    raceCard={raceCard}
                                    underReadyLine={season.under_ready_line}
                                />
                                <SeasonTimeline
                                    weeks={seasonSummary}
                                    detailByWeekStart={detailByWeekStart}
                                    today={today}
                                    raceDate={race?.race_date ?? null}
                                    weekFocus={adaptation}
                                    focusDay={focusDay}
                                    selectedDay={selectedDay}
                                    onMove={moveSession}
                                    onSkip={skipSession}
                                    onUnskip={unskipSession}
                                    onLoadWeek={loadPastWeek}
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
