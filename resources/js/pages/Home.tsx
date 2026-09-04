import { Head } from '@inertiajs/react';

import type {
    ActivityDetail,
    BriefingResult,
    PastYouTrend,
    TrainingLoad,
    WeekPlan,
    WeeklySnapshot,
} from '@/types/inertia';

import EvidenceList from '@/components/home/EvidenceList';
import NoPlanCard from '@/components/home/NoPlanCard';
import NoVerdictPanel from '@/components/home/NoVerdictPanel';
import TodaySession from '@/components/home/TodaySession';
import VerdictHero from '@/components/home/VerdictHero';
import WeekPlanWidget from '@/components/home/WeekPlanWidget';
import WeekStatsDisclosure from '@/components/home/WeekStatsDisclosure';
import EmptyRunsState from '@/components/run/EmptyRunsState';
import PageContainer from '@/components/ui/PageContainer';
import { appLayout } from '@/layouts/appLayout';

interface HomeProps {
    briefing: BriefingResult;
    load: TrainingLoad | null;
    snapshot: WeeklySnapshot | null;
    recentRuns: ActivityDetail[];
    pastYouTrend?: PastYouTrend | null;
    weekPlan?: WeekPlan | null;
}

/**
 * Today, on the frozen prototype's `TodayScreen` section list: the week's plan
 * card (or its empty state), "you vs past you" and the evidence behind it,
 * Temari's read on today, then the week's stats behind a closed disclosure.
 */
export default function Home({
    briefing,
    load,
    snapshot,
    recentRuns,
    pastYouTrend = null,
    weekPlan = null,
}: Readonly<HomeProps>) {
    const lastRun = recentRuns[0] ?? null;
    const hasRuns = recentRuns.length > 0;
    const judged =
        pastYouTrend !== null && pastYouTrend.verdict !== 'not_enough_history'
            ? pastYouTrend.verdict
            : null;

    return (
        <>
            <Head title="Home" />
            <PageContainer>
                {!hasRuns ? (
                    <EmptyRunsState />
                ) : (
                    <div className="flex flex-col gap-4">
                        <TodaySession briefing={briefing} />

                        {weekPlan !== null ? (
                            <WeekPlanWidget weekPlan={weekPlan} />
                        ) : (
                            <NoPlanCard />
                        )}

                        <WeekStatsDisclosure
                            briefing={briefing}
                            load={load}
                            snapshot={snapshot}
                            lastRun={lastRun}
                        />

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
