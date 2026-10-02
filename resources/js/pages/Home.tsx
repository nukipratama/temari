import { Head } from '@inertiajs/react';

import type {
    BriefingResult,
    PastYouTrend,
    RestDayEasePace,
    WeekPlan,
    WeeklySnapshot,
} from '@/types/inertia';

import AiOutageBanner from '@/components/AiOutageBanner';
import EvidenceList from '@/components/home/EvidenceList';
import NoPlanCard from '@/components/home/NoPlanCard';
import NoVerdictPanel from '@/components/home/NoVerdictPanel';
import RaceOutcomePrompt, {
    type PendingRaceOutcome,
} from '@/components/home/RaceOutcomePrompt';
import TodaySession from '@/components/home/TodaySession';
import VerdictHero from '@/components/home/VerdictHero';
import WeekPlanWidget from '@/components/home/WeekPlanWidget';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';
import { drawnHomeAnchors } from '@/lib/anchors';
import { useTodayIso } from '@/lib/pace';
import { laneStack } from '@/lib/variants';

interface HomeProps {
    briefing: BriefingResult;
    snapshot: WeeklySnapshot | null;
    hasRuns: boolean;
    pastYouTrend?: PastYouTrend | null;
    weekPlan?: WeekPlan | null;
    /** Only shipped, deferred, on a planned rest day. */
    restDayEasePace?: RestDayEasePace | null;
    pendingRaceOutcome?: PendingRaceOutcome | null;
}

/**
 * Today, on the frozen prototype's `TodayScreen` section list: Temari's read
 * on today, the week's plan card carrying the week's own numbers (or its empty
 * state), then "you vs past you" and the evidence behind it. The deep stats —
 * vitals and condition — live on Trends.
 */
export default function Home({
    briefing,
    snapshot,
    hasRuns,
    pastYouTrend = null,
    weekPlan = null,
    restDayEasePace = null,
    pendingRaceOutcome = null,
}: Readonly<HomeProps>) {
    const todayIso = useTodayIso();
    const todayPlan =
        weekPlan?.days.find((day) => day.date === todayIso) ?? null;
    const judged =
        pastYouTrend !== null && pastYouTrend.verdict !== 'not_enough_history'
            ? pastYouTrend.verdict
            : null;

    return (
        <>
            <Head title="Home" />
            <AiOutageBanner />
            <PageContainer>
                {!hasRuns ? (
                    <EmptyRunsState />
                ) : (
                    <div className={laneStack}>
                        {pendingRaceOutcome !== null && (
                            <RaceOutcomePrompt race={pendingRaceOutcome} />
                        )}
                        <TodaySession
                            briefing={briefing}
                            today={todayPlan}
                            restDayEasePace={restDayEasePace}
                            drawnAnchors={drawnHomeAnchors(weekPlan, todayIso)}
                        />

                        {weekPlan !== null ? (
                            <WeekPlanWidget
                                weekPlan={weekPlan}
                                snapshot={snapshot}
                            />
                        ) : (
                            <NoPlanCard snapshot={snapshot} />
                        )}

                        {pastYouTrend !== null &&
                            (judged !== null ? (
                                <div>
                                    <VerdictHero
                                        trend={pastYouTrend}
                                        verdict={judged}
                                    />
                                    <EvidenceList trend={pastYouTrend} />
                                </div>
                            ) : (
                                <NoVerdictPanel trend={pastYouTrend} />
                            ))}
                    </div>
                )}
            </PageContainer>
        </>
    );
}

Home.layout = appLayout;
